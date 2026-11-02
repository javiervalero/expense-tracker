import { describe, expect, it } from 'vitest'

import { failedReceipt, pngFile, readyReceipt } from '@/composables/__tests__/receiptHarness'
import { fakeReceiptState, pickFiles } from './receiptPanelHarness'

// The hand-built state the panel specs rely on must itself behave like the real one.

describe('fakeReceiptState', () => {
  it('has a receipt once one is set, or while the upload is processed', () => {
    const { state, set } = fakeReceiptState()
    expect(state.hasReceipt.value).toBe(false)

    set.receipt(readyReceipt())
    expect(state.hasReceipt.value).toBe(true)

    set.receipt(null)
    set.phase('processing')
    expect(state.hasReceipt.value).toBe(true)
  })

  it('follows every setter', () => {
    const { state, set } = fakeReceiptState()
    const file = pngFile()

    set.receipt(failedReceipt())
    set.stalled(true)
    set.loadFailed(true)
    set.pendingReplacement(file)
    set.canRetry(true)

    expect(state.receipt.value).toEqual(failedReceipt())
    expect(state.stalled.value).toBe(true)
    expect(state.loadFailed.value).toBe(true)
    expect(state.pendingReplacement.value).toBe(file)
    expect(state.canRetry.value).toBe(true)
  })
})

describe('pickFiles', () => {
  it('reports the first file name as the value, and records writes', () => {
    const input = document.createElement('input')
    const { valueWrites } = pickFiles(input, [pngFile()])

    expect(input.value).toBe('C:\\fakepath\\lunch.png')
    input.value = ''
    expect(valueWrites).toEqual([''])
  })

  it('reports an empty value without files', () => {
    const input = document.createElement('input')

    pickFiles(input, [])

    expect(input.value).toBe('')
  })
})
