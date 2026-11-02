import { computed, ref, shallowRef } from 'vue'
import { vi } from 'vitest'

import type { Receipt } from '@/api/types'
import type { ChooseOutcome, ReceiptPhase, ReceiptState } from '@/lib/receiptState'

// A hand-built ReceiptState for the ReceiptPanel unit specs: refs the test controls and spies for every action. The
// vi.mock of '@/composables/useReceipt' stays in each spec (it must be hoisted there).

export interface FakeInit {
  phase?: ReceiptPhase
  receipt?: Receipt | null
  progress?: number
  message?: string
  fileMessage?: string
  announcement?: string
  stalled?: boolean
  loadFailed?: boolean
  pendingReplacement?: File | null
  canRetry?: boolean
}

export function fakeReceiptState(init: FakeInit = {}) {
  const phase = ref<ReceiptPhase>(init.phase ?? 'none')
  const receipt = shallowRef<Receipt | null>(init.receipt ?? null)
  const progress = ref(init.progress ?? 0)
  const message = ref(init.message ?? '')
  const fileMessage = ref(init.fileMessage ?? '')
  const announcement = ref(init.announcement ?? '')
  const stalled = ref(init.stalled ?? false)
  const loadFailed = ref(init.loadFailed ?? false)
  const replacement = shallowRef<File | null>(init.pendingReplacement ?? null)
  const retryable = ref(init.canRetry ?? false)

  const actions = {
    start: vi.fn(() => Promise.resolve()),
    choose: vi.fn((): Promise<ChooseOutcome> => Promise.resolve('started')),
    confirmReplace: vi.fn(() => Promise.resolve()),
    cancelReplace: vi.fn(),
    retry: vi.fn(() => Promise.resolve()),
    checkAgain: vi.fn(() => Promise.resolve()),
    remove: vi.fn(() => Promise.resolve()),
    freshView: vi.fn((): Promise<Receipt | null> => Promise.resolve(receipt.value)),
    stop: vi.fn(),
  }

  const state: ReceiptState = {
    phase,
    receipt,
    hasReceipt: computed(() => receipt.value !== null || phase.value === 'processing'),
    progress,
    message,
    fileMessage,
    announcement,
    stalled,
    loadFailed,
    pendingReplacement: computed(() => replacement.value),
    canRetry: computed(() => retryable.value),
    ...actions,
  }

  // `set` writes the sources; the panel only ever sees the read-only `state`.
  const set = {
    phase: (value: ReceiptPhase) => (phase.value = value),
    receipt: (value: Receipt | null) => (receipt.value = value),
    progress: (value: number) => (progress.value = value),
    message: (value: string) => (message.value = value),
    fileMessage: (value: string) => (fileMessage.value = value),
    announcement: (value: string) => (announcement.value = value),
    stalled: (value: boolean) => (stalled.value = value),
    loadFailed: (value: boolean) => (loadFailed.value = value),
    pendingReplacement: (value: File | null) => (replacement.value = value),
    canRetry: (value: boolean) => (retryable.value = value),
  }

  return { state, set, actions }
}

/** A file input that reports `files` (jsdom cannot pick files) and records what is written to its `value`. */
export function pickFiles(input: HTMLInputElement, files: File[]): { valueWrites: string[] } {
  const valueWrites: string[] = []
  Object.defineProperty(input, 'files', { configurable: true, value: files })
  Object.defineProperty(input, 'value', {
    configurable: true,
    get: () =>
      files
        .slice(0, 1)
        .map((file) => `C:\\fakepath\\${file.name}`)
        .join(''),
    set: (next: string) => {
      valueWrites.push(next)
    },
  })
  return { valueWrites }
}

/** The elements whose OWN text contains `text` (the innermost ones, so a wrapper does not count). */
export function elementsWithOwnText(root: ParentNode, text: string): Element[] {
  return Array.from(root.querySelectorAll('*')).filter((element) =>
    Array.from(element.childNodes).some(
      (node) => node.nodeType === Node.TEXT_NODE && String(node.textContent).includes(text),
    ),
  )
}
