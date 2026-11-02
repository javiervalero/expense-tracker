<script setup lang="ts">
import { computed, nextTick, ref, toRaw, useTemplateRef, watch } from 'vue'

import type { Receipt } from '@/api/types'
import PdfIcon from '@/components/PdfIcon.vue'
import { RECEIPT_UNAVAILABLE_TEXT } from '@/lib/receiptMessages'
import { formatReceiptSize } from '@/lib/receiptSize'
import { needsNewAddress } from '@/lib/receiptState'
import { isSafeViewUrl } from '@/lib/receiptUrl'

// Fixed display size of the thumbnail (the server makes it fit this box).
const THUMBNAIL_SIZE = 480

const props = defineProps<{
  receipt: Receipt
  /** The receipt with addresses that stay valid for a while (the composable's freshView). */
  refresh: () => Promise<Receipt | null>
}>()

// An address reaches `src` or `href` only through this check, and the checked string itself is what gets bound.
function safe(address: string | null | undefined): string | null {
  return typeof address === 'string' && isSafeViewUrl(address) ? address : null
}

const isPdf = computed(() => props.receipt.contentType === 'application/pdf')
const thumbnailSrc = computed(() => (isPdf.value ? null : safe(props.receipt.thumbnailUrl)))

// The address of a click-time refresh, already checked, and the receipt value it came with. It is dropped when the page
// shows another receipt value; the fresh value itself (what a successful refresh puts on the page) keeps it.
const refreshedHref = ref<string | null>(null)
let refreshedFor: Receipt | null = null
// A parent may hand the receipt over inside a reactive proxy: values are compared as the plain objects they wrap.
function sameReceipt(left: Receipt, right: Receipt | null): boolean {
  return toRaw(left) === toRaw(right)
}
watch(
  () => props.receipt,
  (now) => {
    if (!sameReceipt(now, refreshedFor)) {
      refreshedHref.value = null
    }
  },
)
const viewHref = computed(() => refreshedHref.value ?? safe(props.receipt.viewUrl))

const link = useTemplateRef<HTMLAnchorElement>('link')
let refreshing = false
// True only around the one programmatic click that follows a refresh: that click is never intercepted (no loop).
let passNextClick = false

// Clicks the anchor only while it is still in the document and still shows the address that was checked; the flag
// never outlives this call.
function followRefreshed(address: string): void {
  passNextClick = true
  try {
    if (link.value && link.value.isConnected && link.value.getAttribute('href') === address) {
      link.value.click()
    }
  } finally {
    passNextClick = false
  }
}

async function refreshThenOpen(): Promise<void> {
  refreshing = true
  try {
    const asked = props.receipt
    const fresh = await props.refresh()
    const address = safe(fresh?.viewUrl)
    if (fresh === null || address === null) {
      return
    }
    // The address is shown only while the page still has the receipt it was asked for (or the fresh one).
    if (sameReceipt(props.receipt, asked) || sameReceipt(props.receipt, fresh)) {
      refreshedFor = fresh
      refreshedHref.value = address
    }
    await nextTick()
    followRefreshed(address)
  } catch {
    // The composable shows its own error text; the old link stays as it was.
  } finally {
    refreshing = false
  }
}

// An address that is about to expire is replaced first; the click is then repeated once on the new address.
function onClick(event: MouseEvent): void {
  if (passNextClick) {
    return
  }
  if (!needsNewAddress(props.receipt)) {
    return
  }
  event.preventDefault()
  if (!refreshing) {
    void refreshThenOpen()
  }
}
</script>

<template>
  <div class="receipt-preview">
    <img
      v-if="thumbnailSrc"
      :src="thumbnailSrc"
      alt="Receipt thumbnail"
      :width="THUMBNAIL_SIZE"
      :height="THUMBNAIL_SIZE"
      loading="lazy"
      referrerpolicy="no-referrer"
    />
    <p v-if="isPdf" class="pdf">
      <PdfIcon />
      <span>PDF, {{ formatReceiptSize(receipt.size) }}</span>
    </p>
    <p v-if="viewHref">
      <a ref="link" :href="viewHref" rel="noreferrer" @click="onClick">Open receipt</a>
    </p>
    <p v-else>{{ RECEIPT_UNAVAILABLE_TEXT }}</p>
  </div>
</template>

<style scoped>
.receipt-preview p {
  margin: 0 0 var(--space-2);
}
img {
  display: block;
  max-width: 100%;
  height: auto;
  margin-bottom: var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}
.pdf {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
</style>
