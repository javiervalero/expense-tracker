import { request } from './http'
import type { ApiResult } from './http'
import type { Credentials, User } from './types'

// The four /auth operations of contract/openapi.yaml. Each states what a 401 means for it (see AuthMode in http.ts).

/** `GET /auth/me`: who is signed in. A 401 is the normal "nobody", so this is a probe and never runs the expiry handler. */
export function me(): Promise<ApiResult<User>> {
  return request<User>('GET', '/auth/me', { auth: 'probe' })
}

/** `POST /auth/login`: 200 with the user. A 401 here means wrong email or password. */
export function login(credentials: Credentials): Promise<ApiResult<User>> {
  return request<User>('POST', '/auth/login', { auth: 'credentials', body: credentials })
}

/** `POST /auth/register`: 201 with the user (and a session). Needs no session. */
export function register(credentials: Credentials): Promise<ApiResult<User>> {
  return request<User>('POST', '/auth/register', { auth: 'public', body: credentials })
}

/** `POST /auth/logout`: 204. A `session` call, so the store removes the expiry handler around it (see session.ts). */
export function logout(): Promise<ApiResult<void>> {
  return request<void>('POST', '/auth/logout', { auth: 'session' })
}
