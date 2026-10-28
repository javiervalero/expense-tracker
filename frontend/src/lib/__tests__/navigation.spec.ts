import { afterEach, describe, expect, it, vi } from 'vitest'

import { isInternalPath, navigateTo, reload } from '../navigation'
import { INTERNAL_PATH_CASES } from './internalPathCases'

const assignMock = vi.fn<(url: string) => void>()

function stubLocation(): void {
  vi.stubGlobal('location', { assign: assignMock })
}

afterEach(() => {
  vi.unstubAllGlobals()
  assignMock.mockReset()
})

describe('isInternalPath', () => {
  it.each(INTERNAL_PATH_CASES)('$name: $internal', ({ value, internal }) => {
    expect(isInternalPath(value)).toBe(internal)
  })
})

describe('navigateTo', () => {
  it.each(INTERNAL_PATH_CASES.filter((c) => c.internal))(
    'does a full page load of the internal path ($name)',
    ({ value }) => {
      stubLocation()

      navigateTo(value)

      expect(assignMock).toHaveBeenCalledExactlyOnceWith(value)
    },
  )

  it.each(INTERNAL_PATH_CASES.filter((c) => !c.internal))(
    'refuses $name and does not navigate',
    ({ value }) => {
      stubLocation()

      expect(() => navigateTo(value)).toThrow(/internal path/)
      expect(assignMock).not.toHaveBeenCalled()
    },
  )
})

describe('reload', () => {
  it('reloads the current page', () => {
    const reloadMock = vi.fn<() => void>()
    vi.stubGlobal('location', { reload: reloadMock })

    reload()

    expect(reloadMock).toHaveBeenCalledOnce()
  })
})
