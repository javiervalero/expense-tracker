import { ref, useTemplateRef } from 'vue'

import type { ApiResult } from '@/api/http'
import type { User } from '@/api/types'
import { useFormErrors } from '@/composables/useFormErrors'
import { checkCredentials, describeAuthError } from '@/lib/authMessages'
import type { AuthForm } from '@/lib/authMessages'
import { useSessionStore } from '@/stores/session'

/**
 * Everything the sign-in and register forms have in common: the two fields, the checks before sending, the pending flag,
 * the error state with its focus rule, and the call to the session store (which is what makes the guards and the App
 * watcher see a signed-in user). A view keeps its own success navigation and any extra element.
 *
 * The form needs `ref="formAlert"` on its `FormAlert` and inputs with the ids `<form>-email` and `<form>-password`.
 */
export function useAuthForm(form: AuthForm) {
  const session = useSessionStore()
  const email = ref('')
  const password = ref('')
  const passwordVisible = ref(false)
  const pending = ref(false)
  const accountExists = ref(false)
  const errors = useFormErrors(['email', 'password'] as const, form)
  const alert = useTemplateRef<{ focus: () => void }>('formAlert')

  function send(credentials: { email: string; password: string }): Promise<ApiResult<User>> {
    return form === 'login' ? session.login(credentials) : session.register(credentials)
  }

  async function showFailure(shown: ReturnType<typeof describeAuthError>): Promise<void> {
    accountExists.value = shown.accountExists
    errors.show(shown.fields, shown.form)
    await errors.focusFirst(alert.value)
  }

  /**
   * Checks, sends, and on success runs `onSuccess` (the view's navigation). A second call while one runs does nothing.
   * `pending` ends when everything has finished, also when the navigation did not happen or something threw, so the
   * form can never stay stuck. The password is hidden again on every submit: a kept value must not stay readable.
   */
  async function submit(onSuccess: () => Promise<unknown>): Promise<void> {
    if (pending.value) {
      return
    }
    passwordVisible.value = false
    errors.clear()
    accountExists.value = false
    const checked = checkCredentials(form, email.value, password.value)
    if (!checked.ok) {
      await showFailure({ fields: checked.fields, form: '', accountExists: false })
      return
    }
    pending.value = true
    let failure: ReturnType<typeof describeAuthError>
    try {
      const result = await send(checked.credentials)
      if (result.ok) {
        await onSuccess()
        return
      }
      failure = describeAuthError(result.error, form)
    } finally {
      pending.value = false
    }
    await showFailure(failure)
  }

  return { email, password, passwordVisible, pending, accountExists, errors, submit }
}
