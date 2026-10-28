import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../http'
import type { Category, CategoryList } from '../types'
import { createCategory, deleteCategory, listCategories, renameCategory } from '../categories'

const requestMock = vi.hoisted(() => vi.fn())

vi.mock('../http', async (original) => ({
  ...(await original<typeof import('../http')>()),
  request: requestMock,
}))

const ID = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'
const category: Category = {
  id: ID,
  name: 'Food',
  version: 1,
  createdAt: '2026-10-01T10:00:00Z',
  updatedAt: '2026-10-01T10:00:00Z',
}

afterEach(() => {
  requestMock.mockReset()
})

describe('categories api', () => {
  it('listCategories() asks GET /categories as a session call', async () => {
    const answer: ApiResult<CategoryList> = { ok: true, status: 200, data: { items: [category] } }
    requestMock.mockResolvedValue(answer)

    const result = await listCategories()

    expect(requestMock).toHaveBeenCalledWith('GET', '/categories', { auth: 'session' })
    expect(result).toBe(answer)
  })

  it('createCategory() posts the name', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 201, data: category })

    await createCategory({ name: 'Food' })

    expect(requestMock).toHaveBeenCalledWith('POST', '/categories', {
      auth: 'session',
      body: { name: 'Food' },
    })
  })

  it('renameCategory() patches the checked id path with name and version', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 200, data: category })

    await renameCategory(ID, { name: 'Meals', version: 3 })

    expect(requestMock).toHaveBeenCalledWith('PATCH', `/categories/${ID}`, {
      auth: 'session',
      body: { name: 'Meals', version: 3 },
    })
  })

  it('deleteCategory() deletes the checked id path', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 204, data: undefined })

    await deleteCategory(ID)

    expect(requestMock).toHaveBeenCalledWith('DELETE', `/categories/${ID}`, { auth: 'session' })
  })

  it.each([
    ['rename', () => renameCategory('../x', { name: 'a', version: 1 })],
    ['delete', () => deleteCategory('a/b')],
  ])('%s refuses an id that is not a plain id, without calling the API', async (_name, call) => {
    const result = await call()

    expect(result).toEqual({ ok: false, error: { kind: 'invalid-request' } })
    expect(requestMock).not.toHaveBeenCalled()
  })
})
