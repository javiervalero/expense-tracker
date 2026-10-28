import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../http'
import { createExpense, deleteExpense, getExpense, listExpenses, updateExpense } from '../expenses'
import type { Expense, ExpensePage } from '../types'

const requestMock = vi.hoisted(() => vi.fn())

vi.mock('../http', async (original) => ({
  ...(await original<typeof import('../http')>()),
  request: requestMock,
}))

const ID = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'
const expense: Expense = {
  id: ID,
  date: '2026-10-01',
  amountCents: 1250,
  description: 'Lunch',
  categoryId: null,
  version: 1,
  createdAt: '2026-10-01T10:00:00Z',
  updatedAt: '2026-10-01T10:00:00Z',
}

afterEach(() => {
  requestMock.mockReset()
})

describe('expenses api', () => {
  it('listExpenses() asks GET /expenses with the query and signal', async () => {
    const answer: ApiResult<ExpensePage> = {
      ok: true,
      status: 200,
      data: { items: [expense], nextCursor: null },
    }
    requestMock.mockResolvedValue(answer)
    const query = new URLSearchParams({ limit: '50' })
    const signal = new AbortController().signal

    const result = await listExpenses(query, signal)

    expect(requestMock).toHaveBeenCalledWith('GET', '/expenses', { auth: 'session', query, signal })
    expect(result).toBe(answer)
  })

  it('listExpenses() works without a query or a signal', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 200, data: { items: [], nextCursor: null } })

    await listExpenses()

    expect(requestMock).toHaveBeenCalledWith('GET', '/expenses', { auth: 'session' })
  })

  it('getExpense() asks GET on the checked id path', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 200, data: expense })

    await getExpense(ID)

    expect(requestMock).toHaveBeenCalledWith('GET', `/expenses/${ID}`, { auth: 'session' })
  })

  it('getExpense() passes the signal when given', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 200, data: expense })
    const signal = new AbortController().signal

    await getExpense(ID, signal)

    expect(requestMock).toHaveBeenCalledWith('GET', `/expenses/${ID}`, { auth: 'session', signal })
  })

  it('createExpense() posts the body', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 201, data: expense })
    const body = { date: '2026-10-01', amountCents: 1250, description: 'Lunch' }

    await createExpense(body)

    expect(requestMock).toHaveBeenCalledWith('POST', '/expenses', { auth: 'session', body })
  })

  it('updateExpense() patches the checked id path', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 200, data: expense })

    await updateExpense(ID, { version: 1, description: 'Dinner' })

    expect(requestMock).toHaveBeenCalledWith('PATCH', `/expenses/${ID}`, {
      auth: 'session',
      body: { version: 1, description: 'Dinner' },
    })
  })

  it('deleteExpense() deletes the checked id path', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 204, data: undefined })

    await deleteExpense(ID)

    expect(requestMock).toHaveBeenCalledWith('DELETE', `/expenses/${ID}`, { auth: 'session' })
  })

  it.each([
    ['getExpense', () => getExpense('../x')],
    ['updateExpense', () => updateExpense('a/b', { version: 1, description: 'x' })],
    ['deleteExpense', () => deleteExpense('')],
  ])('%s() refuses an unsafe id without calling the network', async (_name, call) => {
    const result = await call()

    expect(result).toEqual({ ok: false, error: { kind: 'invalid-request' } })
    expect(requestMock).not.toHaveBeenCalled()
  })
})
