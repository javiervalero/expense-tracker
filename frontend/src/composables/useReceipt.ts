import { computed, getCurrentScope, onScopeDispose, ref, shallowRef, toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'

import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import { deleteReceipt, getReceipt } from '@/api/receipts'
import type { Receipt } from '@/api/types'
import { createPoller } from '@/composables/receiptPolling'
import { runReceiptUpload } from '@/composables/receiptUpload'
import type { UploadOutcome } from '@/composables/receiptUpload'
import { describeExpenseError } from '@/lib/expenseMessages'
import { checkReceiptFile } from '@/lib/receiptFile'
import {
  LOAD_FIRST_TEXT,
  STILL_PROCESSING_MESSAGE,
  deleteErrorMessage,
  failureMessage,
  policyErrorMessage,
  preCheckMessage,
  uploadErrorMessage,
} from '@/lib/receiptMessages'
import {
  ANNOUNCE_FAILED,
  ANNOUNCE_PROCESSING,
  ANNOUNCE_READY,
  ANNOUNCE_UPLOADING,
  PERCENT_DONE,
  PHASE_OF_STATUS,
  needsNewAddress,
} from '@/lib/receiptState'
import type { ChooseOutcome, ChosenFile, ReceiptPhase, ReceiptState } from '@/lib/receiptState'

/** What the page showed before an action began, so that a failed action can put it back. */
interface Snapshot {
  phase: ReceiptPhase
  receipt: Receipt | null
}

// The fixed text for a failure nobody expected (the generic server-failure text of Phase 4).
const GENERIC_FAILURE_TEXT = describeExpenseError({ kind: 'server', status: 500 }, false).form

export type { ChooseOutcome, ReceiptPhase, ReceiptState }

/**
 * The receipt of one expense (plan phase-5 C17 to C21): reading it, the upload, polling while the server checks the
 * file, replace, remove and a fresh view address. Nothing here touches the DOM or the router. Signed addresses and
 * upload policies are never stored, logged or put into a message. The page calls `stop()` when it goes away (it also
 * runs when the owning effect scope is disposed): requests are aborted and late answers change nothing.
 */
export function useReceipt(expenseId: MaybeRefOrGetter<string>): ReceiptState {
  const phase = ref<ReceiptPhase>('loading')
  const receipt = shallowRef<Receipt | null>(null)
  const progress = ref(0)
  const message = ref('')
  const fileMessage = ref('')
  const announcement = ref('')
  const stalled = ref(false)
  // True when the first read failed with anything but a 404: the receipt is unknown, so no file may be picked yet.
  const loadFailed = ref(false)
  const replacement = shallowRef<ChosenFile | null>(null)
  const pendingReplacement = computed(() => replacement.value?.file ?? null)
  // A receipt is there once one was read, or once an upload finished (its record is read on the next poll).
  const hasReceipt = computed(() => receipt.value !== null || phase.value === 'processing')
  const uploading = computed(() => phase.value === 'requesting' || phase.value === 'uploading')

  // Bumped by every new request series and by `stop`: an answer that finds another value belongs to a state that is gone.
  let generation = 0
  let controller = new AbortController()
  // The file of the last upload, kept so that Retry can ask for a NEW policy (an expired one is useless).
  // Cleared by `start`, a successful remove and `stop`; `canRetry` reads it.
  const held = shallowRef<ChosenFile | null>(null)
  // True while Retry would act: the last upload failed after its policy was issued, so the phase is back to none.
  const canRetry = computed(() => phase.value === 'none' && held.value !== null)
  // Set by `stop` and cleared by `start`: while it is set, no action sends a request or arms a timer or listener.
  let stopped = false
  // Set ONLY when the owning scope is disposed (never cleared): a later `start()` is then a no-op.
  let disposed = false
  // The generation of the remove in flight (-1: none), so that freshView leaves a remove alone.
  let removing = -1
  // The error text freshView last showed, so that a later successful read can take back exactly that text.
  let freshViewError = ''

  const poller = createPoller<ApiResult<Receipt>>({
    fetchOnce: (signal) => getReceipt(toValue(expenseId), signal),
    onAnswer: onPollAnswer,
    onBudgetSpent: () => {
      stalled.value = true
      message.value = STILL_PROCESSING_MESSAGE
    },
    onError: () => {
      // A callback of the poller threw (it should never): the poller halted, the page offers "Check again".
      stalled.value = true
      message.value = GENERIC_FAILURE_TEXT
    },
  })

  function cancelInFlight(): number {
    generation += 1
    removing = -1
    controller.abort()
    controller = new AbortController()
    poller.halt()
    return generation
  }

  function newRequest(): { mine: number; signal: AbortSignal } {
    const mine = cancelInFlight()
    return { mine, signal: controller.signal }
  }

  function snapshot(): Snapshot {
    return { phase: phase.value, receipt: receipt.value }
  }

  // Puts back what was shown before an action began; a receipt still being checked (or an upload just finished, whose
  // record is not read yet) polls again. The text is set by the caller after, because showing a receipt clears it.
  function restore(before: Snapshot): void {
    if (before.receipt !== null) {
      enter(before.receipt)
      return
    }
    receipt.value = null
    phase.value = before.phase
    message.value = ''
    if (before.phase === 'processing') {
      poller.begin()
    }
  }

  // Shows a receipt that was read. Returns true while it is still pending (the server is checking it).
  function show(found: Receipt, keepMessage = false): boolean {
    receipt.value = found
    phase.value = PHASE_OF_STATUS[found.status]
    if (found.status === 'failed' && found.failureReason !== null) {
      message.value = failureMessage(found.failureReason)
    } else if (!keepMessage) {
      message.value = ''
    }
    return found.status === 'pending'
  }

  // Shows what is known (or none) and polls when the receipt is pending. The message is cleared, unless `keepMessage`
  // (a refresh of the same receipt must not wipe an unrelated text such as a delete error).
  function enter(found: Receipt | null, keepMessage = false): void {
    if (found === null) {
      receipt.value = null
      phase.value = 'none'
      message.value = ''
    } else if (show(found, keepMessage)) {
      poller.begin()
    }
  }

  // A failed poll ends polling: a 404 means there is no receipt; anything else keeps "processing" with the error text.
  function onPollFailure(error: ApiError): void {
    if (error.kind === 'not-found') {
      enter(null)
      return
    }
    stalled.value = true
    message.value = describeExpenseError(error, false).form
  }

  function onPollAnswer(result: ApiResult<Receipt>): boolean {
    if (!result.ok) {
      onPollFailure(result.error)
      return false
    }
    const pending = show(result.data)
    if (!pending) {
      announcement.value = result.data.status === 'ready' ? ANNOUNCE_READY : ANNOUNCE_FAILED
    }
    return pending
  }

  /** Reads the receipt of the expense and shows it; a pending one starts the polling. */
  async function start(): Promise<void> {
    if (disposed) {
      return
    }
    stopped = false
    held.value = null
    const { mine, signal } = newRequest()
    phase.value = 'loading'
    loadFailed.value = false
    stalled.value = false
    message.value = ''
    fileMessage.value = ''
    replacement.value = null
    const result = await getReceipt(toValue(expenseId), signal)
    if (mine !== generation) {
      return
    }
    if (result.ok) {
      enter(result.data)
      return
    }
    enter(null)
    if (result.error.kind !== 'not-found') {
      loadFailed.value = true
      message.value = describeExpenseError(result.error, false).form
    }
  }

  // receiptUpload promises never to throw (its API calls return results). If it does anyway, the upload counts as
  // interrupted instead of becoming an unhandled rejection; an answer for a state that is gone stays "stale".
  async function runUpload(
    chosen: ChosenFile,
    signal: AbortSignal,
    mine: number,
  ): Promise<UploadOutcome> {
    try {
      return await runReceiptUpload({
        expenseId: toValue(expenseId),
        chosen,
        signal,
        isCurrent: () => mine === generation,
        onUploading: () => {
          phase.value = 'uploading'
          announcement.value = ANNOUNCE_UPLOADING
        },
        onProgress: (percent) => {
          progress.value = percent
        },
      })
    } catch {
      return mine === generation
        ? { kind: 'upload-failed', error: { kind: 'interrupted' } }
        : { kind: 'stale' }
    }
  }

  async function upload(chosen: ChosenFile): Promise<void> {
    if (stopped || uploading.value || removing !== -1) {
      return
    }
    held.value = chosen
    const before = snapshot()
    const { mine, signal } = newRequest()
    message.value = ''
    fileMessage.value = ''
    stalled.value = false
    progress.value = 0
    phase.value = 'requesting'
    const outcome = await runUpload(chosen, signal, mine)
    if (outcome.kind === 'stale') {
      return
    }
    if (outcome.kind === 'done') {
      held.value = null
      receipt.value = null
      progress.value = PERCENT_DONE
      phase.value = 'processing'
      announcement.value = ANNOUNCE_PROCESSING
      poller.begin()
      return
    }
    if (outcome.kind === 'upload-failed') {
      // The policy was issued, so the server already replaced the old receipt: it is gone, and so are its addresses.
      receipt.value = null
      phase.value = 'none'
      message.value = uploadErrorMessage(outcome.error) ?? ''
      return
    }
    // A refused file is not offered again: no Retry beside "Choose another file".
    held.value = null
    restore(before)
    if (outcome.error.kind === 'validation') {
      fileMessage.value = policyErrorMessage(outcome.error)
    } else {
      message.value = policyErrorMessage(outcome.error)
    }
  }

  function chooseNow(file: File): ChooseOutcome {
    if (stopped) {
      return 'rejected'
    }
    if (phase.value === 'loading' || loadFailed.value) {
      fileMessage.value = LOAD_FIRST_TEXT
      return 'rejected'
    }
    if (uploading.value) {
      return 'rejected'
    }
    if (removing !== -1) {
      return 'rejected'
    }
    const checked = checkReceiptFile(file)
    if (!checked.ok) {
      fileMessage.value = preCheckMessage(checked.reason)
      return 'rejected'
    }
    fileMessage.value = ''
    const chosen = { file, contentType: checked.contentType }
    if (hasReceipt.value) {
      replacement.value = chosen
      return 'needs-confirmation'
    }
    void upload(chosen)
    return 'started'
  }

  /**
   * A file was picked. Refused while the receipt is not known yet (loading, or the first read failed), while an upload
   * is on its way, after `stop`, or when the browser pre-check fails; asks for confirmation when a receipt exists
   * (replace deletes it at once); otherwise the upload begins (the call does not wait for it).
   */
  function choose(file: File): Promise<ChooseOutcome> {
    return Promise.resolve(chooseNow(file))
  }

  /** The user confirmed the replacement of the existing receipt. Resolves when the upload starts, not when it ends. */
  function confirmReplace(): Promise<void> {
    const chosen = replacement.value
    if (!stopped && chosen !== null) {
      replacement.value = null
      void upload(chosen)
    }
    return Promise.resolve()
  }

  function cancelReplace(): void {
    replacement.value = null
  }

  /** Asks for a new policy and uploads the same file again (after an expired or interrupted upload). */
  function retry(): Promise<void> {
    if (!stopped && held.value !== null) {
      void upload(held.value)
    }
    return Promise.resolve()
  }

  /** "Check again" after the polling gave up: a fresh budget, one request now. Only while the page shows "stalled". */
  function checkAgain(): Promise<void> {
    if (stopped || phase.value !== 'processing' || !stalled.value) {
      return Promise.resolve()
    }
    stalled.value = false
    message.value = ''
    return poller.restart()
  }

  /** Deletes the receipt. A 404 means it is already gone. Any other failure puts back what was shown and shows the text. */
  async function remove(): Promise<void> {
    if (stopped || uploading.value || phase.value === 'loading' || loadFailed.value) {
      return
    }
    const before = snapshot()
    const { mine, signal } = newRequest()
    removing = mine
    const result = await deleteReceipt(toValue(expenseId), signal)
    if (mine !== generation) {
      return
    }
    removing = -1
    if (result.ok || result.error.kind === 'not-found') {
      held.value = null
      enter(null)
      message.value = result.ok ? '' : deleteErrorMessage(result.error)
      return
    }
    restore(before)
    message.value = deleteErrorMessage(result.error)
  }

  // A failed read of freshView. It took the request slot and so halted the polling: a 404 means the receipt is gone;
  // anything else keeps the receipt (polling again when the server is still checking it) and shows the error text.
  function keepAfterFailedRead(error: ApiError): null {
    if (error.kind === 'not-found') {
      enter(null)
      return null
    }
    if (receipt.value?.status === 'pending') {
      poller.begin()
    }
    freshViewError = describeExpenseError(error, false).form
    message.value = freshViewError
    return null
  }

  /**
   * The receipt with addresses that stay valid for a while: the current one, or one read again when its addresses are
   * (nearly) expired. The current one is also returned, unrefreshed, while an upload or a remove is on its way (a new
   * read would cancel it). Null when stopped, when there is no receipt or when the new read failed (the text is in
   * `message`). The new read has its own request slot: only the latest request of the page may change the state.
   */
  async function freshView(): Promise<Receipt | null> {
    if (stopped) {
      return null
    }
    const current = receipt.value
    if (current === null || !needsNewAddress(current)) {
      return current
    }
    if (uploading.value || removing === generation) {
      return current
    }
    const { mine, signal } = newRequest()
    const result = await getReceipt(toValue(expenseId), signal)
    if (mine !== generation) {
      return null
    }
    if (!result.ok) {
      return keepAfterFailedRead(result.error)
    }
    // The same receipt, only with new addresses: an unrelated text (a delete error) stays; only freshView's own goes.
    const keepMessage = message.value !== freshViewError
    freshViewError = ''
    enter(result.data, keepMessage)
    return result.data
  }

  /**
   * The page is going away: abort what is in flight, stop the timers and drop every late answer. Terminal until
   * `start()`: meanwhile no action sends a request or arms a timer or listener.
   */
  function stop(): void {
    stopped = true
    held.value = null
    cancelInFlight()
  }

  if (getCurrentScope() !== undefined) {
    onScopeDispose(() => {
      disposed = true
      stop()
    })
  }

  return {
    phase,
    receipt,
    hasReceipt,
    progress,
    message,
    fileMessage,
    announcement,
    stalled,
    loadFailed,
    pendingReplacement,
    canRetry,
    start,
    choose,
    confirmReplace,
    cancelReplace,
    retry,
    checkAgain,
    remove,
    freshView,
    stop,
  }
}
