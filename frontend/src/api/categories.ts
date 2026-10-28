import { request, resourcePath } from './http'
import type { ApiResult } from './http'
import type { Category, CategoryList, CategoryRename, NewCategory } from './types'

// The four category operations of contract/openapi.yaml. All need the session, so a 401 runs the expiry handler.

const INVALID_ID: ApiResult<never> = { ok: false, error: { kind: 'invalid-request' } }

/** `GET /categories`: every category of the user, sorted by the server (at most 100, no paging). */
export function listCategories(): Promise<ApiResult<CategoryList>> {
  return request<CategoryList>('GET', '/categories', { auth: 'session' })
}

/** `POST /categories`: 201 with the new category. 409 for a taken name or the limit of 100. */
export function createCategory(body: NewCategory): Promise<ApiResult<Category>> {
  return request<Category>('POST', '/categories', { auth: 'session', body })
}

/** `PATCH /categories/{id}`: 200 with the category. The body must carry the `version` last read (409 if stale). */
export function renameCategory(id: string, body: CategoryRename): Promise<ApiResult<Category>> {
  const path = resourcePath('/categories', id)
  return path === null
    ? Promise.resolve(INVALID_ID)
    : request<Category>('PATCH', path, { auth: 'session', body })
}

/** `DELETE /categories/{id}`: 204. A second delete of the same id is a 404. */
export function deleteCategory(id: string): Promise<ApiResult<void>> {
  const path = resourcePath('/categories', id)
  return path === null
    ? Promise.resolve(INVALID_ID)
    : request<void>('DELETE', path, { auth: 'session' })
}
