<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useId, useTemplateRef, watch } from 'vue'

import ConfirmDeleteButton from '@/components/ConfirmDeleteButton.vue'
import FormAlert from '@/components/FormAlert.vue'
import ReceiptPreview from '@/components/ReceiptPreview.vue'
import { useReceipt } from '@/composables/useReceipt'
import { RECEIPT_TYPES } from '@/config'
import {
  CANCEL_LABEL,
  CHECK_AGAIN_LABEL,
  DELETE_RECEIPT_LABEL,
  LOADING_TEXT,
  LOAD_FIRST_TEXT,
  PROCESSING_TEXT,
  REPLACE_RECEIPT_LABEL,
  RETRY_LABEL,
  RULES_TEXT,
  STILL_PROCESSING_MESSAGE,
  TRY_AGAIN_LABEL,
  UPLOADING_TEXT,
  UPLOAD_RUNNING_TEXT,
  failureMessage,
} from '@/lib/receiptMessages'
import { ANNOUNCE_FAILED } from '@/lib/receiptState'

const props = defineProps<{ expenseId: string }>()

const ACCEPT = RECEIPT_TYPES.join(',')
// The keys that open the file picker on a focused file input.
const PICKER_KEYS: readonly string[] = ['Enter', ' ']

const {
  phase,
  receipt,
  progress,
  message,
  fileMessage,
  announcement,
  stalled,
  loadFailed,
  pendingReplacement,
  canRetry,
  start,
  choose,
  confirmReplace,
  cancelReplace,
  retry,
  checkAgain,
  remove,
  freshView,
} = useReceipt(() => props.expenseId)

onMounted(() => {
  void start()
})

const headingId = useId()
const inputId = useId()
const rulesId = useId()
const fileMessageId = useId()
const progressTextId = useId()

const uploading = computed(() => phase.value === 'requesting' || phase.value === 'uploading')
// True while a request of the user's own is on its way and its outcome is still to come.
const busy = computed(() => uploading.value || phase.value === 'loading')
const showInput = computed(() => phase.value !== 'loading' && !loadFailed.value)
const canDelete = computed(() => ['ready', 'failed', 'processing'].includes(phase.value))
const readyReceipt = computed(() => (phase.value === 'ready' ? receipt.value : null))
// A file picked while an upload runs never reaches the composable: the panel answers it itself, in the same alert.
const busyNotice = ref('')
const messageKey = ref(0)
watch(uploading, (now) => {
  if (!now) {
    busyNotice.value = ''
  }
})
const shownFileMessage = computed(() => busyNotice.value || fileMessage.value)
const describedby = computed(() =>
  shownFileMessage.value ? `${rulesId} ${fileMessageId}` : rulesId,
)

// "Still processing" is not an error: a neutral status next to "Check again", never the alert.
const stillProcessing = computed(
  () => phase.value === 'processing' && stalled.value && message.value === STILL_PROCESSING_MESSAGE,
)

// A receipt the server refused is explained in text. The composable also puts that text in `message`: one alert only.
const failureText = computed(() => {
  if (phase.value !== 'failed') {
    return ''
  }
  const reason = receipt.value?.failureReason
  return reason ? failureMessage(reason) : ANNOUNCE_FAILED
})
const alertText = computed(() => (stillProcessing.value ? '' : message.value || failureText.value))

// Focus goes to the alert when a text appears, never when it changes or goes away, and ONLY for the outcome of
// something the user did (a rejected policy, a failed upload, a failed delete or replace, a failed "Try again").
// A text that comes from polling (budget spent, a failed status, a poll error) or from the page loading must not take
// focus from where the user is. The composable does not say where a text came from, so the panel marks it itself:
// `userTriggered` is set by the panel's own handlers and cleared when the request they started is over.
let userTriggered = false
const alert = useTemplateRef<{ focus: () => void }>('alert')
watch(alertText, async (now, before) => {
  if (now && !before && userTriggered) {
    await nextTick()
    alert.value?.focus()
  }
})
watch(busy, async (now) => {
  if (!now) {
    // After the watchers of this same update have looked at the mark.
    await nextTick()
    userTriggered = false
  }
})

// When the control that had focus is gone, focus falls to the top of the page. It goes to the file input if there is
// one, else to the panel heading. A user who moved focus elsewhere keeps it.
const heading = useTemplateRef<HTMLElement>('heading')
const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
function focusIfLost(): void {
  const active = document.activeElement
  if (active === null || active === document.body) {
    ;(fileInput.value ?? heading.value)?.focus()
  }
}

interface RunOptions {
  /** The outcome of the action may be a message that takes focus (see `userTriggered`). */
  announce: boolean
  /** The control only goes away when the action is over (a request), not as soon as it starts. */
  waitFirst: boolean
}

// The mark ends with the request the action started: when `busy` falls (see the watcher above), or at once when the
// action started none; for a delete, which has no busy phase, when it has answered.
async function run(action: () => unknown, options: RunOptions): Promise<void> {
  userTriggered = options.announce
  const pending = Promise.resolve(action())
  if (options.waitFirst) {
    await pending
    await nextTick()
    userTriggered = false
  } else if (!busy.value) {
    userTriggered = false
  }
  await nextTick()
  focusIfLost()
  await pending
}

const onTryAgain = () => run(start, { announce: true, waitFirst: false })
const onRetry = () => run(retry, { announce: true, waitFirst: false })
const onCheckAgain = () => run(checkAgain, { announce: false, waitFirst: false })
const onConfirmReplace = () => run(confirmReplace, { announce: true, waitFirst: false })
const onCancelReplace = () => run(cancelReplace, { announce: false, waitFirst: false })
// A delete the panel knows will do nothing (a request is on its way, or the receipt is unknown) is not the user's
// outcome to announce: the mark is set only for a delete that actually starts.
const onRemove = () => run(remove, { announce: !busy.value && !loadFailed.value, waitFirst: true })

// The file input is aria-disabled, not disabled, while an upload runs. Disabled still means disabled: the picker does
// not open (no click, no Enter or Space), so nothing is chosen and then silently dropped; the progress text says why.
function holdClickWhileUploading(event: MouseEvent): void {
  if (uploading.value) {
    event.preventDefault()
  }
}
function holdKeysWhileUploading(event: KeyboardEvent): void {
  if (uploading.value && PICKER_KEYS.includes(event.key)) {
    event.preventDefault()
  }
}

// The picked file is dropped from the input either way, so the same file can be picked again.
// Every pick gives the file message a new element (`messageKey`): the same text twice is announced twice.
async function onPick(event: Event): Promise<void> {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (!file) {
    return
  }
  messageKey.value += 1
  if (uploading.value) {
    busyNotice.value = UPLOAD_RUNNING_TEXT
    return
  }
  userTriggered = true
  if ((await choose(file)) !== 'started') {
    userTriggered = false
  }
}
</script>

<template>
  <section class="receipt-panel" :aria-labelledby="headingId">
    <h2 :id="headingId" ref="heading" tabindex="-1">Receipt</h2>
    <!-- Always in the page, from the first render: a live region added later is not announced. Milestones only. -->
    <div class="visually-hidden" aria-live="polite">{{ announcement }}</div>

    <p v-if="phase === 'loading'">{{ LOADING_TEXT }}</p>
    <div v-else-if="loadFailed" class="actions">
      <!-- The alert below carries the reason; this text is only for when it has none. -->
      <p v-if="!alertText">{{ LOAD_FIRST_TEXT }}</p>
      <button type="button" @click="onTryAgain()">{{ TRY_AGAIN_LABEL }}</button>
    </div>

    <template v-if="showInput">
      <div class="field">
        <label :for="inputId">Receipt file</label>
        <input
          :id="inputId"
          ref="fileInput"
          type="file"
          :accept="ACCEPT"
          :aria-describedby="describedby"
          :aria-disabled="uploading ? 'true' : undefined"
          :aria-invalid="fileMessage ? 'true' : undefined"
          @click="holdClickWhileUploading"
          @keydown="holdKeysWhileUploading"
          @change="onPick"
        />
        <p :id="rulesId" class="hint">{{ RULES_TEXT }}</p>
        <p
          v-if="shownFileMessage"
          :id="fileMessageId"
          :key="messageKey"
          class="hint file-message"
          role="alert"
        >
          {{ shownFileMessage }}
        </p>
      </div>

      <div v-if="uploading" class="progress">
        <p :id="progressTextId">{{ UPLOADING_TEXT }} {{ progress }}%</p>
        <progress :value="progress" max="100" :aria-labelledby="progressTextId"></progress>
      </div>
      <p v-else-if="phase === 'processing' && !stalled">{{ PROCESSING_TEXT }}</p>
      <ReceiptPreview v-else-if="readyReceipt" :receipt="readyReceipt" :refresh="freshView" />
    </template>

    <FormAlert ref="alert" :message="alertText" />

    <div v-if="pendingReplacement" class="actions">
      <ConfirmDeleteButton
        :label="REPLACE_RECEIPT_LABEL"
        confirm-label="Confirm replace"
        question="Replace the current receipt? It is deleted at once."
        :subject="pendingReplacement.name"
        @confirm="onConfirmReplace"
      />
      <button type="button" @click="onCancelReplace()">{{ CANCEL_LABEL }}</button>
    </div>

    <div class="actions">
      <button v-if="canRetry" type="button" @click="onRetry()">{{ RETRY_LABEL }}</button>
      <template v-if="phase === 'processing' && stalled">
        <p v-if="stillProcessing" class="status-note" role="status">
          {{ STILL_PROCESSING_MESSAGE }}
        </p>
        <button type="button" @click="onCheckAgain()">{{ CHECK_AGAIN_LABEL }}</button>
      </template>
      <ConfirmDeleteButton
        v-if="canDelete"
        :label="DELETE_RECEIPT_LABEL"
        question="Delete this receipt?"
        @confirm="onRemove"
      />
    </div>
  </section>
</template>

<style scoped>
.receipt-panel {
  margin-top: var(--space-4);
  padding-top: var(--space-3);
  border-top: 1px solid var(--color-border);
}
h2 {
  margin: 0 0 var(--space-2);
  font-size: 1.25rem;
}
.field {
  margin-bottom: var(--space-3);
}
label {
  display: block;
  margin-bottom: var(--space-1);
  font-weight: 600;
}
.hint {
  margin: var(--space-1) 0 0;
  color: var(--color-muted);
  font-size: 0.875rem;
}
.file-message {
  color: var(--color-danger);
}
.progress progress {
  width: 100%;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
}
/* Neutral: the page is working, nothing is wrong. Same box as the alert, without the danger colours. */
.status-note {
  margin: 0;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  color: var(--color-text);
  overflow-wrap: anywhere;
}
</style>
