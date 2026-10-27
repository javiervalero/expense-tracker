import { nextTick, ref } from 'vue'

/** What `FormAlert` offers: the form-level message takes focus when no field is at fault. */
interface Focusable {
  focus: () => void
}

/**
 * The error state of one form: a message per field and one for the whole form, plus the focus rule of plan phase-4 C15
 * (on a failed submit, focus goes to the first invalid field, or to the form alert). Field inputs must have the id
 * `<idPrefix>-<field>`, which is what `FormField` is given.
 */
export function useFormErrors<Field extends string>(fields: readonly Field[], idPrefix: string) {
  const fieldErrors = ref<Partial<Record<Field, string>>>({})
  const formMessage = ref('')

  function clear(): void {
    fieldErrors.value = {}
    formMessage.value = ''
  }

  /** Replaces all messages at once. */
  function show(next: Partial<Record<Field, string>>, form: string): void {
    fieldErrors.value = { ...next }
    formMessage.value = form
  }

  function hasErrors(): boolean {
    return (
      formMessage.value !== '' || fields.some((field) => fieldErrors.value[field] !== undefined)
    )
  }

  /** Waits for the messages to be drawn, then focuses the first field (in `fields` order) with one, else the alert. */
  async function focusFirst(alert: Focusable | null | undefined): Promise<void> {
    await nextTick()
    const first = fields.find((field) => fieldErrors.value[field] !== undefined)
    const input = first === undefined ? null : document.getElementById(`${idPrefix}-${first}`)
    if (input) {
      input.focus()
    } else {
      alert?.focus()
    }
  }

  return { fieldErrors, formMessage, clear, show, hasErrors, focusFirst }
}
