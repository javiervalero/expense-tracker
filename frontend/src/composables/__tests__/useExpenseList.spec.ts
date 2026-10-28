import { beforeEach, describe, expect, it, vi } from 'vitest'

import { deferred, expense, failed, pageOf, uid } from '@/api/__tests__/expenseFixtures'
import { MAX_AUTO_FOLLOW } from '@/config'
import { NO_FILTERS } from '@/lib/expenseQuery'
import { useExpenseList } from '../useExpenseList'

const api = vi.hoisted(() => ({ listExpenses: vi.fn(), deleteExpense: vi.fn() }))
vi.mock('@/api/expenses', () => api)

const A = expense(uid(1))
const B = expense(uid(2))
const C = expense(uid(3))

function paramsOfCall(index: number): URLSearchParams {
  return api.listExpenses.mock.calls.at(index)?.[0] as URLSearchParams
}
function signalOfCall(index: number): AbortSignal {
  return api.listExpenses.mock.calls.at(index)?.[1] as AbortSignal
}
const emptyWithCursor = (cursor: string) => pageOf([], cursor)

beforeEach(() => {
  api.listExpenses.mockReset()
  api.deleteExpense.mockReset()
})

describe('useExpenseList: first page', () => {
  it('loads the first page with limit=50 and no cursor', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B]))
    const list = useExpenseList()

    const pending = list.search(NO_FILTERS)
    expect(list.status.value).toBe('loading')
    await pending

    expect(list.items.value).toEqual([A, B])
    expect(list.status.value).toBe('ready')
    expect(list.hasMore.value).toBe(false)
    expect(paramsOfCall(0).toString()).toBe('limit=50')
  })

  it('sends the filters it was given', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A]))
    const list = useExpenseList()

    await list.search({ from: '2026-10-01', to: '', category: 'none', q: 'lunch' })

    expect(paramsOfCall(0).toString()).toBe('from=2026-10-01&category=none&q=lunch&limit=50')
  })

  it('refuses an unchecked date or category before any request', async () => {
    const list = useExpenseList()

    await list.search({ from: 'nope', to: '', category: 'ABC', q: '' })

    expect(api.listExpenses).not.toHaveBeenCalled()
    expect(list.status.value).toBe('idle')
  })

  it('sends q trimmed', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([]))
    const list = useExpenseList()

    await list.search({ from: '', to: '', category: '', q: '  tea ' })

    expect(paramsOfCall(0).toString()).toBe('q=tea&limit=50')
  })

  it('refuses from after to before any request', async () => {
    const list = useExpenseList()
    api.listExpenses.mockResolvedValueOnce(pageOf([A]))
    await list.search(NO_FILTERS)

    await list.search({ from: '2026-10-02', to: '2026-10-01', category: '', q: '' })

    expect(api.listExpenses).toHaveBeenCalledTimes(1)
    expect(list.items.value).toEqual([])
    expect(list.status.value).toBe('idle')
  })

  it('shows a fixed message when the first page fails, and a new search recovers', async () => {
    api.listExpenses.mockResolvedValueOnce(failed({ kind: 'network' }))
    const list = useExpenseList()

    await list.search(NO_FILTERS)

    expect(list.status.value).toBe('failed')
    expect(list.message.value).toBe("Can't reach the server. Check your connection and try again.")
    api.listExpenses.mockResolvedValueOnce(pageOf([A]))
    await list.search(NO_FILTERS)
    expect(list.status.value).toBe('ready')
    expect(list.message.value).toBe('')
  })

  it('does not restart a 400 on the first page', async () => {
    api.listExpenses.mockResolvedValue(failed({ kind: 'bad-request', status: 400 }))
    const list = useExpenseList()

    await list.search(NO_FILTERS)

    expect(api.listExpenses).toHaveBeenCalledTimes(1)
    expect(list.status.value).toBe('failed')
    expect(list.message.value).toBe('The request was not accepted. Reload the page and try again.')
  })

  it('follows an empty first page that has a cursor', async () => {
    api.listExpenses
      .mockResolvedValueOnce(emptyWithCursor('c1'))
      .mockResolvedValueOnce(pageOf([A], null))
    const list = useExpenseList()

    await list.search(NO_FILTERS)

    expect(list.items.value).toEqual([A])
    expect(paramsOfCall(1).get('cursor')).toBe('c1')
  })
})

describe('useExpenseList: Load more', () => {
  it('sends the same filters plus the cursor and appends the items', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A], 'c1')).mockResolvedValueOnce(pageOf([B]))
    const list = useExpenseList()
    await list.search({ from: '2026-10-01', to: '', category: 'none', q: 'lunch' })

    const outcome = await list.loadMore()

    expect(paramsOfCall(1).toString()).toBe(
      'from=2026-10-01&category=none&q=lunch&limit=50&cursor=c1',
    )
    expect(list.items.value).toEqual([A, B])
    expect(outcome).toBe(1)
    expect(list.hasMore.value).toBe(false)
  })

  it('passes the cursor through untouched', async () => {
    const cursor = 'eyJ4Ijoi+/=_-~ & %41'
    api.listExpenses.mockResolvedValueOnce(pageOf([A], cursor)).mockResolvedValueOnce(pageOf([B]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    await list.loadMore()

    expect(paramsOfCall(1).get('cursor')).toBe(cursor)
  })

  it('keeps the button while the cursor is not null, whatever the length of the page', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A], 'c1'))
    const list = useExpenseList()

    await list.search(NO_FILTERS)

    expect(list.hasMore.value).toBe(true)
    api.listExpenses.mockResolvedValueOnce(
      pageOf(
        Array.from({ length: 50 }, (_, i) => expense(uid(100 + i))),
        'c2',
      ),
    )
    await list.loadMore()
    expect(list.hasMore.value).toBe(true)
  })

  it('hides the button only when the cursor is null (an empty cursor counts as null)', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A], ''))
    const list = useExpenseList()

    await list.search(NO_FILTERS)

    expect(list.hasMore.value).toBe(false)
  })

  it('ignores Load more when there is no cursor', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    expect(await list.loadMore()).toBeNull()
    expect(api.listExpenses).toHaveBeenCalledTimes(1)
  })

  it('ignores a second Load more while one is in flight', async () => {
    const slow = deferred<ReturnType<typeof pageOf>>()
    api.listExpenses.mockResolvedValueOnce(pageOf([A], 'c1')).mockReturnValueOnce(slow.promise)
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    const first = list.loadMore()
    expect(list.loadingMore.value).toBe(true)
    const second = await list.loadMore()
    slow.resolve(pageOf([B]))
    await first

    expect(second).toBeNull()
    expect(api.listExpenses).toHaveBeenCalledTimes(2)
    expect(list.loadingMore.value).toBe(false)
  })

  it('keeps the rows and the button when Load more fails', async () => {
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'server', status: 500 }))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    const outcome = await list.loadMore()

    expect(outcome).toBeNull()
    expect(list.items.value).toEqual([A])
    expect(list.hasMore.value).toBe(true)
    expect(list.status.value).toBe('ready')
    expect(list.message.value).toBe('Something went wrong on our side. Try again.')
  })
})

describe('useExpenseList: empty pages with a cursor', () => {
  it('follows at most MAX_AUTO_FOLLOW extra requests per click, then asks to keep searching', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A], 'c1'))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    api.listExpenses
      .mockResolvedValueOnce(emptyWithCursor('c2'))
      .mockResolvedValueOnce(emptyWithCursor('c3'))
      .mockResolvedValueOnce(emptyWithCursor('c4'))
      .mockResolvedValueOnce(emptyWithCursor('c5'))

    const outcome = await list.loadMore()

    expect(MAX_AUTO_FOLLOW).toBe(3)
    // the search, the click itself, and three more
    expect(api.listExpenses).toHaveBeenCalledTimes(1 + 1 + MAX_AUTO_FOLLOW)
    expect(paramsOfCall(4).get('cursor')).toBe('c4')
    expect(list.keepSearching.value).toBe(true)
    expect(list.hasMore.value).toBe(true)
    expect(outcome).toBe(0)
  })

  it('"Keep searching" goes on from the last cursor with a fresh budget', async () => {
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockResolvedValueOnce(emptyWithCursor('c2'))
      .mockResolvedValueOnce(emptyWithCursor('c3'))
      .mockResolvedValueOnce(emptyWithCursor('c4'))
      .mockResolvedValueOnce(emptyWithCursor('c5'))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    await list.loadMore()

    api.listExpenses
      .mockResolvedValueOnce(emptyWithCursor('c6'))
      .mockResolvedValueOnce(pageOf([B], null))
    const outcome = await list.loadMore()

    expect(paramsOfCall(5).get('cursor')).toBe('c5')
    expect(list.items.value).toEqual([A, B])
    expect(list.keepSearching.value).toBe(false)
    expect(list.hasMore.value).toBe(false)
    expect(outcome).toBe(1)
  })

  it('stops following as soon as a page has items', async () => {
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockResolvedValueOnce(emptyWithCursor('c2'))
      .mockResolvedValueOnce(pageOf([B], 'c3'))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    await list.loadMore()

    expect(api.listExpenses).toHaveBeenCalledTimes(3)
    expect(list.keepSearching.value).toBe(false)
    expect(list.hasMore.value).toBe(true)
  })

  it('asks to keep searching after an empty first stretch too', async () => {
    api.listExpenses.mockResolvedValue(emptyWithCursor('cx'))
    const list = useExpenseList()

    await list.search(NO_FILTERS)

    expect(api.listExpenses).toHaveBeenCalledTimes(1 + MAX_AUTO_FOLLOW)
    expect(list.items.value).toEqual([])
    expect(list.keepSearching.value).toBe(true)
    expect(list.status.value).toBe('ready')
  })
})

describe('useExpenseList: a filter change', () => {
  it('aborts the request in flight and ignores its late answer', async () => {
    const first = deferred<ReturnType<typeof pageOf>>()
    api.listExpenses.mockReturnValueOnce(first.promise).mockResolvedValueOnce(pageOf([B]))
    const list = useExpenseList()

    const old = list.search({ ...NO_FILTERS, q: 'old' })
    await list.search({ ...NO_FILTERS, q: 'new' })
    expect(signalOfCall(0).aborted).toBe(true)
    first.resolve(pageOf([A], 'late'))
    await old

    expect(list.items.value).toEqual([B])
    expect(list.hasMore.value).toBe(false)
    expect(list.status.value).toBe('ready')
  })

  it('ignores the late failure of the old request as well', async () => {
    const first = deferred<ReturnType<typeof failed>>()
    api.listExpenses.mockReturnValueOnce(first.promise).mockResolvedValueOnce(pageOf([B]))
    const list = useExpenseList()

    const old = list.search(NO_FILTERS)
    await list.search({ ...NO_FILTERS, q: 'new' })
    first.resolve(failed({ kind: 'aborted' }))
    await old

    expect(list.status.value).toBe('ready')
    expect(list.message.value).toBe('')
  })

  it('aborts a Load more in flight and drops its answer', async () => {
    const slow = deferred<ReturnType<typeof pageOf>>()
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockReturnValueOnce(slow.promise)
      .mockResolvedValueOnce(pageOf([C]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    const more = list.loadMore()
    await list.search({ ...NO_FILTERS, q: 'x' })
    slow.resolve(pageOf([B], 'c2'))
    const outcome = await more

    expect(signalOfCall(1).aborted).toBe(true)
    expect(outcome).toBeNull()
    expect(list.items.value).toEqual([C])
    expect(list.loadingMore.value).toBe(false)
  })

  it('stop() aborts what is in flight and drops the answer', async () => {
    const slow = deferred<ReturnType<typeof pageOf>>()
    api.listExpenses.mockReturnValueOnce(slow.promise)
    const list = useExpenseList()

    const pending = list.search(NO_FILTERS)
    list.stop()
    slow.resolve(pageOf([A]))
    await pending

    expect(signalOfCall(0).aborted).toBe(true)
    expect(list.items.value).toEqual([])
  })
})

describe('useExpenseList: a 400 on a cursor request', () => {
  it('restarts from page one once', async () => {
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'bad-request', status: 400 }))
      .mockResolvedValueOnce(pageOf([C], 'c9'))
    const list = useExpenseList()
    await list.search({ ...NO_FILTERS, q: 'tea' })

    const outcome = await list.loadMore()

    expect(paramsOfCall(2).toString()).toBe('q=tea&limit=50')
    expect(list.items.value).toEqual([C])
    expect(list.hasMore.value).toBe(true)
    expect(list.restarted.value).toBe(true)
    expect(outcome).toBe('restarted')
  })

  it('does not restart a second time in the same click', async () => {
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'bad-request', status: 400 }))
      .mockResolvedValueOnce(emptyWithCursor('c9'))
      .mockResolvedValueOnce(failed({ kind: 'bad-request', status: 400 }))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    const outcome = await list.loadMore()

    expect(api.listExpenses).toHaveBeenCalledTimes(4)
    expect(outcome).toBeNull()
    expect(list.message.value).toBe('The request was not accepted. Reload the page and try again.')
  })

  it('shows the failure when the restarted first page fails', async () => {
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'bad-request', status: 400 }))
      .mockResolvedValueOnce(failed({ kind: 'network' }))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    await list.loadMore()

    expect(api.listExpenses).toHaveBeenCalledTimes(3)
    expect(list.status.value).toBe('failed')
    expect(list.items.value).toEqual([])
  })

  it('clears the restart notice on the next search', async () => {
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'bad-request', status: 400 }))
      .mockResolvedValueOnce(pageOf([C]))
      .mockResolvedValueOnce(pageOf([B]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    await list.loadMore()

    await list.search({ ...NO_FILTERS, q: 'z' })

    expect(list.restarted.value).toBe(false)
  })
})

describe('useExpenseList: remove', () => {
  async function loaded() {
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B, C]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    return list
  }

  it('removes the row on 204', async () => {
    const list = await loaded()
    api.deleteExpense.mockResolvedValueOnce({ ok: true, status: 204, data: undefined })

    const outcome = await list.remove(B.id)

    expect(api.deleteExpense).toHaveBeenCalledWith(B.id)
    expect(outcome).toBe('deleted')
    expect(list.items.value).toEqual([A, C])
  })

  it('removes the row on 404 and reports it as already deleted', async () => {
    const list = await loaded()
    api.deleteExpense.mockResolvedValueOnce(failed({ kind: 'not-found', status: 404 }))

    const outcome = await list.remove(B.id)

    expect(outcome).toBe('already')
    expect(list.items.value).toEqual([A, C])
  })

  it('keeps the row and gives a message for any other failure', async () => {
    const list = await loaded()
    api.deleteExpense.mockResolvedValueOnce(failed({ kind: 'timeout' }))

    const outcome = await list.remove(B.id)

    expect(outcome).toBe('failed')
    expect(list.items.value).toEqual([A, B, C])
    expect(list.rowErrors.value.get(B.id)).toBe(
      "Can't reach the server. Check your connection and try again. It may have been saved; check the list before trying again.",
    )
    expect(list.deleting.value.has(B.id)).toBe(false)
  })

  it('drops the answer of a delete that was started before a new search: no row error, null outcome', async () => {
    const list = await loaded()
    const slow = deferred<unknown>()
    api.deleteExpense.mockReturnValueOnce(slow.promise)
    const stale = list.remove(B.id)
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B, C]))
    await list.search(NO_FILTERS)

    slow.resolve(failed({ kind: 'timeout' }))
    const outcome = await stale

    expect(outcome).toBeNull()
    expect(list.rowErrors.value.size).toBe(0)
    expect(list.items.value).toEqual([A, B, C])
    expect(list.deleting.value.has(B.id)).toBe(false)
  })

  it('marks the row busy while the call is in flight and ignores a second delete', async () => {
    const list = await loaded()
    const slow = deferred<{ ok: true; status: number; data: undefined }>()
    api.deleteExpense.mockReturnValueOnce(slow.promise)

    const first = list.remove(B.id)
    expect(list.deleting.value.has(B.id)).toBe(true)
    const second = await list.remove(B.id)
    slow.resolve({ ok: true, status: 204, data: undefined })
    await first

    expect(second).toBeNull()
    expect(api.deleteExpense).toHaveBeenCalledTimes(1)
    expect(list.deleting.value.has(B.id)).toBe(false)
  })

  it('clears an earlier row error when the delete is tried again', async () => {
    const list = await loaded()
    api.deleteExpense
      .mockResolvedValueOnce(failed({ kind: 'server', status: 500 }))
      .mockResolvedValueOnce({ ok: true, status: 204, data: undefined })
    await list.remove(B.id)

    await list.remove(B.id)

    expect(list.rowErrors.value.get(B.id)).toBeUndefined()
  })
})

describe('useExpenseList: rows that come back and repeat', () => {
  it('does not bring back a row deleted while Load more was in flight', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B], 'c1'))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    const slow = deferred<ReturnType<typeof pageOf>>()
    api.listExpenses.mockReturnValueOnce(slow.promise)
    api.deleteExpense.mockResolvedValueOnce({ ok: true, status: 204, data: undefined })

    const more = list.loadMore()
    await list.remove(B.id)
    slow.resolve(pageOf([B, C]))
    const outcome = await more

    expect(list.items.value).toEqual([A, C])
    expect(outcome).toBe(1)
  })

  it('replaces a repeated id with the newer copy instead of adding a second row', async () => {
    const newer = { ...B, description: 'Newer copy', version: 2 }
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A, B], 'c1'))
      .mockResolvedValueOnce(pageOf([newer, C]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    const outcome = await list.loadMore()

    expect(list.items.value).toEqual([A, newer, C])
    expect(outcome).toBe(1)
  })

  it('forgets the deleted ids on a new search', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    api.deleteExpense.mockResolvedValueOnce({ ok: true, status: 204, data: undefined })
    await list.remove(B.id)
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B]))

    await list.search(NO_FILTERS)

    expect(list.items.value).toEqual([A, B])
  })
})

describe('useExpenseList: row errors', () => {
  it('does not read inherited properties for a row called "constructor"', async () => {
    const odd = expense('constructor')
    api.listExpenses.mockResolvedValueOnce(pageOf([odd, A]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    expect(list.rowErrors.value.get('constructor')).toBeUndefined()
    api.deleteExpense.mockResolvedValueOnce(failed({ kind: 'server', status: 500 }))
    await list.remove(A.id)
    expect(list.rowErrors.value.get('constructor')).toBeUndefined()
    expect(list.rowErrors.value.get(A.id)).toBe('Something went wrong on our side. Try again.')
  })

  it('clears the row errors when a new search starts, and leaves the busy rows alone', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    api.deleteExpense.mockResolvedValueOnce(failed({ kind: 'server', status: 500 }))
    await list.remove(A.id)
    const slow = deferred<{ ok: true; status: number; data: undefined }>()
    api.deleteExpense.mockReturnValueOnce(slow.promise)
    const busy = list.remove(B.id)
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B]))

    await list.search(NO_FILTERS)

    expect(list.rowErrors.value.size).toBe(0)
    expect(list.deleting.value.has(B.id)).toBe(true)
    slow.resolve({ ok: true, status: 204, data: undefined })
    await busy
  })
})

describe('useExpenseList: server text is never shown', () => {
  const SECRET = 'Hand-written server detail'

  it('shows the generic text for a 422 on a list request', async () => {
    api.listExpenses.mockResolvedValueOnce(
      failed({ kind: 'validation', status: 422, fieldErrors: {}, formErrors: [SECRET] }),
    )
    const list = useExpenseList()

    await list.search(NO_FILTERS)

    expect(list.message.value).toBe('Something went wrong on our side. Try again.')
    expect(list.message.value).not.toContain(SECRET)
  })

  it('shows the generic text for a 409 on a list request', async () => {
    api.listExpenses.mockResolvedValueOnce(
      failed({ kind: 'conflict', status: 409, detail: SECRET }),
    )
    const list = useExpenseList()

    await list.search(NO_FILTERS)

    expect(list.message.value).toBe('Something went wrong on our side. Try again.')
  })

  it('shows the generic text for a 409 or 422 on a delete', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    api.deleteExpense
      .mockResolvedValueOnce(failed({ kind: 'conflict', status: 409, detail: SECRET }))
      .mockResolvedValueOnce(
        failed({ kind: 'validation', status: 422, fieldErrors: { id: SECRET }, formErrors: [] }),
      )

    await list.remove(A.id)
    await list.remove(B.id)

    expect(list.rowErrors.value.get(A.id)).toBe('Something went wrong on our side. Try again.')
    expect(list.rowErrors.value.get(B.id)).toBe('Something went wrong on our side. Try again.')
  })
})

describe('useExpenseList: settled searches', () => {
  it('counts a search that finished, and not one that was superseded or refused', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A]))
    const list = useExpenseList()
    expect(list.settled.value).toBe(0)

    await list.search(NO_FILTERS)
    expect(list.settled.value).toBe(1)

    await list.search({ from: '2026-10-02', to: '2026-10-01', category: '', q: '' })
    expect(list.settled.value).toBe(1)
  })
})

describe('useExpenseList: what a page really adds', () => {
  it('adds a repeated id inside one page only once', async () => {
    const copy = { ...C, description: 'Second copy' }
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A], 'c1'))
      .mockResolvedValueOnce(pageOf([C, copy]))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    const outcome = await list.loadMore()

    expect(list.items.value).toEqual([A, copy])
    expect(outcome).toBe(1)
  })

  it('follows a page of only known ids like an empty page, and it counts toward the cap', async () => {
    api.listExpenses
      .mockResolvedValueOnce(pageOf([A, B], 'c1'))
      .mockResolvedValueOnce(pageOf([A], 'c2'))
      .mockResolvedValueOnce(emptyWithCursor('c3'))
      .mockResolvedValueOnce(emptyWithCursor('c4'))
      .mockResolvedValueOnce(emptyWithCursor('c5'))
    const list = useExpenseList()
    await list.search(NO_FILTERS)

    const outcome = await list.loadMore()

    expect(api.listExpenses).toHaveBeenCalledTimes(1 + 1 + MAX_AUTO_FOLLOW)
    expect(list.keepSearching.value).toBe(true)
    expect(outcome).toBe(0)
  })

  it('follows a page whose rows were all deleted in the meantime', async () => {
    api.listExpenses.mockResolvedValueOnce(pageOf([A, B], 'c1'))
    const list = useExpenseList()
    await list.search(NO_FILTERS)
    api.deleteExpense.mockResolvedValueOnce({ ok: true, status: 204, data: undefined })
    await list.remove(B.id)
    api.listExpenses.mockResolvedValueOnce(pageOf([B], 'c2')).mockResolvedValueOnce(pageOf([C]))

    const outcome = await list.loadMore()

    expect(list.items.value).toEqual([A, C])
    expect(outcome).toBe(1)
    expect(list.keepSearching.value).toBe(false)
  })
})
