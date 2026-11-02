import type { ApiError } from '@/api/problem'
import { createReceiptUpload } from '@/api/receipts'
import { uploadReceipt } from '@/api/upload'
import type { UploadError } from '@/api/upload'
import type { ChosenFile } from '@/lib/receiptState'

/** How one upload ended. The signed policy never leaves this module. */
export type UploadOutcome =
  | { kind: 'stale' }
  | { kind: 'policy-refused'; error: ApiError }
  | { kind: 'upload-failed'; error: UploadError }
  | { kind: 'done' }

export interface UploadRun {
  expenseId: string
  chosen: ChosenFile
  signal: AbortSignal
  /** False once the page moved on: the next step is then not taken. */
  isCurrent: () => boolean
  onUploading: () => void
  onProgress: (percent: number) => void
}

/**
 * The two steps of an upload: a policy for the type and size of the very file that is then sent, and the browser upload
 * to the signed address. The type and size sent to the server come from the same `chosen` file as the upload itself.
 */
export async function runReceiptUpload(run: UploadRun): Promise<UploadOutcome> {
  const { chosen, signal } = run
  const policy = await createReceiptUpload(
    run.expenseId,
    { contentType: chosen.contentType, size: chosen.file.size },
    signal,
  )
  if (!run.isCurrent()) {
    return { kind: 'stale' }
  }
  if (!policy.ok) {
    return { kind: 'policy-refused', error: policy.error }
  }
  run.onUploading()
  const { url, fields } = policy.data
  const uploaded = await uploadReceipt({ url, fields }, chosen.file, {
    onProgress: run.onProgress,
    signal,
  })
  if (!run.isCurrent()) {
    return { kind: 'stale' }
  }
  return uploaded.ok ? { kind: 'done' } : { kind: 'upload-failed', error: uploaded.error }
}
