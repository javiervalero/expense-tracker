import { nextTick, ref } from 'vue'

/**
 * A short message that must survive a change of page, such as "Expense saved" after the form sends the user back to the
 * list. `App.vue` shows it in a polite live region that stays in the page, and clears it on the next navigation.
 */
export const flashMessage = ref('')

export function clearFlash(): void {
  flashMessage.value = ''
}

/** The text is emptied first, so the same message twice in a row is a change a screen reader reads again. */
export async function setFlash(message: string): Promise<void> {
  flashMessage.value = ''
  await nextTick()
  flashMessage.value = message
}
