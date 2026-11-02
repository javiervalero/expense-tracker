import { ref } from 'vue'

import { getMonthlyReport } from '@/api/reports'
import type { MonthlyReport } from '@/api/types'
import { describeSummaryError } from '@/lib/summaryMessages'

type Status = 'idle' | 'loading' | 'error' | 'loaded'

/**
 * The monthly report of one page (plan phase-6 D13): one request per month change. The previous request is aborted and a
 * generation counter drops any answer that is no longer wanted (plan phase-4 R7). Nothing here touches the DOM or the
 * router; the page calls `stop()` when it goes away.
 */
export function useMonthlyReport() {
  const status = ref<Status>('idle')
  const data = ref<MonthlyReport | null>(null)
  const message = ref('')
  // The month of the current request or answer ('' before the first load).
  const month = ref('')

  // Bumped by every load and by `stop`: an answer that finds another value belongs to a request that is gone.
  let generation = 0
  let controller: AbortController | null = null

  function cancelInFlight(): number {
    generation += 1
    controller?.abort()
    controller = null
    return generation
  }

  /** Asks for a month. The old request is aborted and the old data cleared. */
  async function load(next: string): Promise<void> {
    const mine = cancelInFlight()
    month.value = next
    data.value = null
    message.value = ''
    status.value = 'loading'
    const own = new AbortController()
    controller = own
    const result = await getMonthlyReport(next, own.signal)
    if (mine !== generation) {
      return
    }
    if (result.ok) {
      data.value = result.data
      status.value = 'loaded'
      return
    }
    message.value = describeSummaryError(result.error)
    status.value = 'error'
  }

  /** The same month again (after an error). Nothing happens before the first load. */
  function retry(): Promise<void> {
    return month.value === '' ? Promise.resolve() : load(month.value)
  }

  /** The page is going away: abort what is in flight and drop its answer. */
  function stop(): void {
    cancelInFlight()
  }

  return { status, data, message, month, load, retry, stop }
}
