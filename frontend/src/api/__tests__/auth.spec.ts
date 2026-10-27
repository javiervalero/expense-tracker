import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../http'
import type { User } from '../types'
import { login, logout, me, register } from '../auth'

const requestMock = vi.hoisted(() => vi.fn())

vi.mock('../http', () => ({ request: requestMock }))

const user: User = { id: 'u1', email: 'a@example.com' }
const credentials = { email: 'a@example.com', password: 'a-long-password-1' }
const okResult: ApiResult<User> = { ok: true, status: 200, data: user }

afterEach(() => {
  requestMock.mockReset()
})

describe('auth api', () => {
  it('me() asks GET /auth/me as a probe', async () => {
    requestMock.mockResolvedValue(okResult)

    const result = await me()

    expect(requestMock).toHaveBeenCalledWith('GET', '/auth/me', { auth: 'probe' })
    expect(result).toBe(okResult)
  })

  it('login() posts the credentials with the credentials auth mode', async () => {
    requestMock.mockResolvedValue(okResult)

    await login(credentials)

    expect(requestMock).toHaveBeenCalledWith('POST', '/auth/login', {
      auth: 'credentials',
      body: credentials,
    })
  })

  it('register() posts the credentials as a public call', async () => {
    requestMock.mockResolvedValue(okResult)

    await register(credentials)

    expect(requestMock).toHaveBeenCalledWith('POST', '/auth/register', {
      auth: 'public',
      body: credentials,
    })
  })

  it('logout() posts with the session auth mode', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 204, data: undefined })

    await logout()

    expect(requestMock).toHaveBeenCalledWith('POST', '/auth/logout', { auth: 'session' })
  })
})
