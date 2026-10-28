import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'

import { clearFlash, flashMessage, setFlash } from '../flash'

describe('flash', () => {
  it('holds a message until it is cleared', async () => {
    await setFlash('Expense saved')
    expect(flashMessage.value).toBe('Expense saved')

    clearFlash()
    expect(flashMessage.value).toBe('')
  })

  it('empties the text first, so the same message twice is a change again', async () => {
    await setFlash('Expense saved')
    const pending = setFlash('Expense saved')

    expect(flashMessage.value).toBe('')
    await nextTick()
    await pending
    expect(flashMessage.value).toBe('Expense saved')
    clearFlash()
  })
})
