import type { ComputedRef, Ref } from 'vue'

import type { Receipt } from '@/api/types'
import { URL_REFRESH_MARGIN_MS } from '@/config'
import type { ReceiptType } from '@/lib/receiptFile'

// Pure pieces of the receipt state machine (`useReceipt`).

export type ReceiptPhase =
  'loading' | 'none' | 'requesting' | 'uploading' | 'processing' | 'ready' | 'failed'

/** A file that passed the browser pre-check, with the type that check narrowed. */
export interface ChosenFile {
  file: File
  contentType: ReceiptType
}

/** What `choose` did with a file: refused it, asks the user to confirm a replacement, or began the upload. */
export type ChooseOutcome = 'rejected' | 'needs-confirmation' | 'started'

/** A ref the page may read and watch but not assign to. */
export type ReadonlyRef<T> = Readonly<Ref<T>>

/** What `useReceipt` returns. The state is changed only through the actions. */
export interface ReceiptState {
  phase: ReadonlyRef<ReceiptPhase>
  receipt: ReadonlyRef<Receipt | null>
  hasReceipt: ComputedRef<boolean>
  progress: ReadonlyRef<number>
  /** The alert text. */
  message: ReadonlyRef<string>
  /** The text under the file input. */
  fileMessage: ReadonlyRef<string>
  /** The polite live region: milestones only, never the percent. */
  announcement: ReadonlyRef<string>
  /** True once polling gave up (or a poll failed): the page offers "Check again". */
  stalled: ReadonlyRef<boolean>
  /** True when the first read failed (not a 404): no file can be picked until `start()` succeeds. */
  loadFailed: ReadonlyRef<boolean>
  pendingReplacement: ComputedRef<File | null>
  start: () => Promise<void>
  choose: (file: File) => Promise<ChooseOutcome>
  confirmReplace: () => Promise<void>
  cancelReplace: () => void
  retry: () => Promise<void>
  checkAgain: () => Promise<void>
  remove: () => Promise<void>
  freshView: () => Promise<Receipt | null>
  stop: () => void
}

export const PHASE_OF_STATUS = {
  pending: 'processing',
  ready: 'ready',
  failed: 'failed',
} as const satisfies Record<Receipt['status'], ReceiptPhase>

// The milestones the polite live region announces (plan C21). Progress percents are never announced.
export const ANNOUNCE_UPLOADING = 'Uploading receipt'
export const ANNOUNCE_PROCESSING = 'Receipt uploaded, processing'
export const ANNOUNCE_READY = 'Receipt ready'
export const ANNOUNCE_FAILED = 'Receipt could not be processed'

export const PERCENT_DONE = 100

/** True when the signed addresses of a receipt expire within the margin (plan decision 6), or cannot be read. */
export function needsNewAddress(receipt: Receipt): boolean {
  if (receipt.urlsExpireAt === null) {
    return false
  }
  return !(Date.parse(receipt.urlsExpireAt) - Date.now() > URL_REFRESH_MARGIN_MS)
}
