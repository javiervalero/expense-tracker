import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { Category } from '@/api/types'
import ConfirmDeleteButton from '@/components/ConfirmDeleteButton.vue'
import CategoriesView from '../CategoriesView.vue'

const api = vi.hoisted(() => ({
  listCategories: vi.fn(),
  createCategory: vi.fn(),
  renameCategory: vi.fn(),
  deleteCategory: vi.fn(),
}))
vi.mock('@/api/categories', () => api)

const HOSTILE = '<img src=x onerror=alert(1)>'
const EMOJI = '\u{1F600}'

function cat(id: string, name: string, version = 1): Category {
  return { id, name, version, createdAt: '2026-10-01T10:00:00Z', updatedAt: '2026-10-01T10:00:00Z' }
}
function ok<T>(data: T, status = 200): ApiResult<T> {
  return { ok: true, status, data }
}
function failure(error: ApiError): ApiResult<never> {
  return { ok: false, error }
}
const listOf = (...items: Category[]) => ok({ items })

type Page = Awaited<ReturnType<typeof render>>

async function render(list: ApiResult<unknown> = listOf()) {
  api.listCategories.mockResolvedValueOnce(list)
  const pinia = createPinia()
  setActivePinia(pinia)
  const host = document.createElement('div')
  document.body.appendChild(host)
  const wrapper = mount(CategoriesView, { attachTo: host, global: { plugins: [pinia] } })
  await flushPromises()
  return wrapper
}

async function addCategory(page: Page, name: string) {
  await page.get('#category-name').setValue(name)
  await page.get('form').trigger('submit')
  await flushPromises()
}

function button(page: Page, text: string) {
  const found = page.findAll('button').find((b) => b.text() === text)
  if (!found) {
    throw new Error(`no button "${text}"`)
  }
  return found
}

const names = (page: Page) => page.findAll('li bdi').map((n) => n.text())

beforeEach(() => {
  Object.values(api).forEach((mock) => mock.mockReset())
})
afterEach(() => {
  document.body.replaceChildren()
})

describe('CategoriesView: list', () => {
  it('has one h1 and says "No categories yet" when there are none', async () => {
    const page = await render()

    expect(page.findAll('h1')).toHaveLength(1)
    expect(page.get('h1').text()).toBe('Categories')
    expect(page.get('.empty').text()).toBe('No categories yet')
    expect(page.find('ul').exists()).toBe(false)
  })

  it('lists the categories in the order the server gave, each name inside bdi', async () => {
    const page = await render(listOf(cat('b', 'Bread'), cat('a', 'apples')))

    expect(names(page)).toEqual(['Bread', 'apples'])
    expect(page.find('.empty').exists()).toBe(false)
  })

  it('says it is loading until the list arrives', async () => {
    api.listCategories.mockReturnValueOnce(new Promise(() => {}))
    const pinia = createPinia()
    setActivePinia(pinia)
    const page = mount(CategoriesView, { global: { plugins: [pinia] } })
    await flushPromises()

    expect(page.text()).toContain('Loading categories')
    expect(page.find('.empty').exists()).toBe(false)
  })

  it('shows a fixed message and a Retry button when the list cannot be loaded', async () => {
    const page = await render(failure({ kind: 'network' }))

    expect(page.get('[role="alert"]').text()).toBe(
      "Can't reach the server. Check your connection and try again.",
    )
    expect(page.find('.empty').exists()).toBe(false)

    api.listCategories.mockResolvedValueOnce(listOf(cat('a', 'Apples')))
    await button(page, 'Retry').trigger('click')
    await flushPromises()

    expect(names(page)).toEqual(['Apples'])
    expect(page.find('[role="alert"]').exists()).toBe(false)
  })

  it('renders a hostile name as text inside bdi, never as markup', async () => {
    const page = await render(listOf(cat('a', HOSTILE)))

    expect(page.find('li img').exists()).toBe(false)
    expect(page.get('li bdi').text()).toBe(HOSTILE)
    expect(page.get('li bdi').element.children).toHaveLength(0)
  })

  it('warns that the expenses of a deleted category become Uncategorized', async () => {
    const page = await render(listOf(cat('a', 'Apples')))

    expect(page.text()).toContain('its expenses become Uncategorized')
  })
})

describe('CategoriesView: create', () => {
  it('sends the trimmed name, clears the field and shows the server order', async () => {
    const page = await render(listOf(cat('a', 'Apples'), cat('z', 'Zoo')))
    api.createCategory.mockResolvedValue(ok(cat('b', 'Food'), 201))
    api.listCategories.mockResolvedValueOnce(
      listOf(cat('a', 'Apples'), cat('b', 'Food'), cat('z', 'Zoo')),
    )

    await addCategory(page, '  Food  ')

    expect(api.createCategory).toHaveBeenCalledWith({ name: 'Food' })
    expect(names(page)).toEqual(['Apples', 'Food', 'Zoo'])
    expect((page.get('#category-name').element as HTMLInputElement).value).toBe('')
  })

  it('refuses a name of 51 code points without a request, and shows it at the field', async () => {
    const page = await render()

    await addCategory(page, 'a'.repeat(51))

    expect(api.createCategory).not.toHaveBeenCalled()
    expect(page.get('#category-name-error').text()).toBe('Name can have at most 50 characters.')
    expect(page.get('#category-name').attributes('aria-invalid')).toBe('true')
  })

  it('accepts a name of exactly 50 code points', async () => {
    api.createCategory.mockResolvedValue(ok(cat('b', 'a'.repeat(50)), 201))
    const page = await render()
    api.listCategories.mockResolvedValueOnce(listOf(cat('b', 'a'.repeat(50))))

    await addCategory(page, 'a'.repeat(50))

    expect(api.createCategory).toHaveBeenCalledWith({ name: 'a'.repeat(50) })
  })

  it('counts code points, not UTF-16 units: 50 emoji are accepted, 51 are refused', async () => {
    api.createCategory.mockResolvedValue(ok(cat('b', EMOJI.repeat(50)), 201))
    const page = await render()
    api.listCategories.mockResolvedValueOnce(listOf(cat('b', EMOJI.repeat(50))))

    await addCategory(page, EMOJI.repeat(51))
    expect(api.createCategory).not.toHaveBeenCalled()

    await addCategory(page, EMOJI.repeat(50))
    expect(api.createCategory).toHaveBeenCalledWith({ name: EMOJI.repeat(50) })
  })

  it('does not put a maxlength attribute on the name input', async () => {
    const page = await render()

    expect(page.get('#category-name').attributes('maxlength')).toBeUndefined()
  })

  it('refuses a blank name, also one made of spaces', async () => {
    const page = await render()

    await addCategory(page, '   ')

    expect(api.createCategory).not.toHaveBeenCalled()
    expect(page.get('#category-name-error').text()).toBe('Enter a name.')
  })

  it('shows the 409 detail at form level and keeps the typed name', async () => {
    const page = await render()
    api.createCategory.mockResolvedValue(
      failure({
        kind: 'conflict',
        status: 409,
        detail: 'A category with this name already exists.',
      }),
    )

    await addCategory(page, 'Food')

    expect(page.get('[role="alert"]').text()).toBe('A category with this name already exists.')
    expect((page.get('#category-name').element as HTMLInputElement).value).toBe('Food')
    expect(document.activeElement?.getAttribute('role')).toBe('alert')
  })

  it('shows a 422 at /name under the field and focuses the field', async () => {
    const page = await render()
    api.createCategory.mockResolvedValue(
      failure({
        kind: 'validation',
        status: 422,
        fieldErrors: { name: 'Name must not contain control characters.' },
        formErrors: [],
      }),
    )

    await addCategory(page, 'Food')

    expect(page.get('#category-name-error').text()).toBe(
      'Name must not contain control characters.',
    )
    expect(document.activeElement?.id).toBe('category-name')
  })

  it('shows a 422 with the empty pointer at form level', async () => {
    const page = await render()
    api.createCategory.mockResolvedValue(
      failure({ kind: 'validation', status: 422, fieldErrors: {}, formErrors: ['Bad body.'] }),
    )

    await addCategory(page, 'Food')

    expect(page.get('[role="alert"]').text()).toBe('Bad body.')
    expect(page.find('#category-name-error').exists()).toBe(false)
  })

  it('ignores a second submit while the first is in flight', async () => {
    const page = await render()
    api.createCategory.mockReturnValue(new Promise(() => {}))
    await page.get('#category-name').setValue('Food')

    await page.get('form').trigger('submit')
    await page.get('form').trigger('submit')

    expect(api.createCategory).toHaveBeenCalledTimes(1)
  })
})

describe('CategoriesView: rename', () => {
  async function editing(version = 3) {
    const page = await render(listOf(cat('c1', 'Food', version)))
    await button(page, 'Rename').trigger('click')
    await flushPromises()
    return page
  }
  const input = (page: Page) => page.get('#rename-c1-name')

  it('opens a field with the current name and focuses it', async () => {
    const page = await editing()

    expect((input(page).element as HTMLInputElement).value).toBe('Food')
    expect(document.activeElement).toBe(input(page).element)
  })

  it('sends name and version, shows the answer, and sends the new version next time', async () => {
    const page = await editing(3)
    api.renameCategory.mockResolvedValue(ok(cat('c1', 'Meals', 4)))

    await input(page).setValue('  Meals ')
    await page.get('li form').trigger('submit')
    await flushPromises()

    expect(api.renameCategory).toHaveBeenCalledWith('c1', { name: 'Meals', version: 3 })
    expect(names(page)).toEqual(['Meals'])
    expect(page.find('li form').exists()).toBe(false)
    expect(document.activeElement?.textContent?.trim()).toBe('Rename')

    await button(page, 'Rename').trigger('click')
    await flushPromises()
    api.renameCategory.mockResolvedValue(ok(cat('c1', 'Dining', 5)))
    await input(page).setValue('Dining')
    await page.get('li form').trigger('submit')
    await flushPromises()
    expect(api.renameCategory).toHaveBeenLastCalledWith('c1', { name: 'Dining', version: 4 })
  })

  it('refuses 51 code points and accepts 50 astral characters', async () => {
    const page = await editing()
    api.renameCategory.mockResolvedValue(ok(cat('c1', EMOJI.repeat(50), 4)))

    await input(page).setValue(EMOJI.repeat(51))
    await page.get('li form').trigger('submit')
    await flushPromises()
    expect(api.renameCategory).not.toHaveBeenCalled()
    expect(page.get('#rename-c1-name-error').text()).toBe('Name can have at most 50 characters.')

    await input(page).setValue(EMOJI.repeat(50))
    await page.get('li form').trigger('submit')
    await flushPromises()
    expect(api.renameCategory).toHaveBeenCalledWith('c1', { name: EMOJI.repeat(50), version: 3 })
  })

  it('sends no request when the name did not change', async () => {
    const page = await editing()

    await input(page).setValue('  Food ')
    await page.get('li form').trigger('submit')
    await flushPromises()

    expect(api.renameCategory).not.toHaveBeenCalled()
    expect(page.find('li form').exists()).toBe(false)
  })

  it('Escape cancels the rename, keeps the old name and returns focus to Rename', async () => {
    const page = await editing()
    await input(page).setValue('Meals')

    await input(page).trigger('keydown', { key: 'Escape' })
    await flushPromises()

    expect(page.find('li form').exists()).toBe(false)
    expect(names(page)).toEqual(['Food'])
    expect(api.renameCategory).not.toHaveBeenCalled()
    expect(document.activeElement?.textContent?.trim()).toBe('Rename')
  })

  it('Cancel does the same as Escape', async () => {
    const page = await editing()

    await button(page, 'Cancel').trigger('click')
    await flushPromises()

    expect(page.find('li form').exists()).toBe(false)
  })

  it('on a 409 shows the detail and Reload; Reload brings the new version and name', async () => {
    const page = await editing(3)
    api.renameCategory.mockResolvedValueOnce(
      failure({
        kind: 'conflict',
        status: 409,
        detail: 'The category was changed by someone else.',
      }),
    )
    await input(page).setValue('Meals')
    await page.get('li form').trigger('submit')
    await flushPromises()

    expect(page.get('[role="alert"]').text()).toBe('The category was changed by someone else.')

    api.listCategories.mockResolvedValueOnce(listOf(cat('c1', 'Groceries', 7)))
    await button(page, 'Reload').trigger('click')
    await flushPromises()

    expect(page.find('[role="alert"]').exists()).toBe(false)
    expect(button(page, 'Save').exists()).toBe(true)
    expect((input(page).element as HTMLInputElement).value).toBe('Groceries')
    api.renameCategory.mockResolvedValueOnce(ok(cat('c1', 'Meals', 8)))
    await input(page).setValue('Meals')
    await page.get('li form').trigger('submit')
    await flushPromises()
    expect(api.renameCategory).toHaveBeenLastCalledWith('c1', { name: 'Meals', version: 7 })
  })

  it('ignores a second click on Reload while the first is loading', async () => {
    const page = await editing()
    api.renameCategory.mockResolvedValueOnce(
      failure({ kind: 'conflict', status: 409, detail: 'Changed.' }),
    )
    await input(page).setValue('Meals')
    await page.get('li form').trigger('submit')
    await flushPromises()
    api.listCategories.mockClear()
    api.listCategories.mockReturnValue(new Promise(() => {}))

    await button(page, 'Reload').trigger('click')
    await button(page, 'Reload').trigger('click')

    expect(api.listCategories).toHaveBeenCalledTimes(1)
  })

  it('shows a failed Reload as a message and keeps editing', async () => {
    const page = await editing()
    api.renameCategory.mockResolvedValueOnce(
      failure({ kind: 'conflict', status: 409, detail: 'Changed.' }),
    )
    await input(page).setValue('Meals')
    await page.get('li form').trigger('submit')
    await flushPromises()
    api.listCategories.mockResolvedValueOnce(failure({ kind: 'network' }))

    await button(page, 'Reload').trigger('click')
    await flushPromises()

    expect(page.get('[role="alert"]').text()).toContain("Can't reach the server")
    expect((input(page).element as HTMLInputElement).value).toBe('Meals')
  })

  it('shows a 422 at /name under the field', async () => {
    const page = await editing()
    api.renameCategory.mockResolvedValue(
      failure({
        kind: 'validation',
        status: 422,
        fieldErrors: { name: 'Too odd.' },
        formErrors: [],
      }),
    )

    await input(page).setValue('Meals')
    await page.get('li form').trigger('submit')
    await flushPromises()

    expect(page.get('#rename-c1-name-error').text()).toBe('Too odd.')
    expect(document.activeElement).toBe(input(page).element)
  })

  it('on a 404 removes the row and says the category no longer exists', async () => {
    const page = await editing()
    api.renameCategory.mockResolvedValue(failure({ kind: 'not-found', status: 404 }))

    await input(page).setValue('Meals')
    await page.get('li form').trigger('submit')
    await flushPromises()

    expect(page.find('li').exists()).toBe(false)
    expect(page.get('[role="status"]').text()).toBe('This category no longer exists.')
  })

  it('ignores a second submit while the first is in flight', async () => {
    const page = await editing()
    api.renameCategory.mockReturnValue(new Promise(() => {}))

    await input(page).setValue('Meals')
    await page.get('li form').trigger('submit')
    await page.get('li form').trigger('submit')

    expect(api.renameCategory).toHaveBeenCalledTimes(1)
  })
})

describe('CategoriesView: delete', () => {
  async function confirmDelete(page: Page) {
    await button(page, 'Delete').trigger('click')
    await button(page, 'Confirm delete').trigger('click')
    await flushPromises()
  }

  it('asks first: nothing is sent until the user confirms', async () => {
    const page = await render(listOf(cat('c1', 'Food')))

    await button(page, 'Delete').trigger('click')

    expect(api.deleteCategory).not.toHaveBeenCalled()
    expect(page.get('[role="group"]').attributes('aria-label')).toContain(
      'Its expenses become Uncategorized',
    )
  })

  it('removes the row on 204', async () => {
    const page = await render(listOf(cat('c1', 'Food'), cat('c2', 'Zoo')))
    api.deleteCategory.mockResolvedValue(ok(undefined, 204))

    await confirmDelete(page)

    expect(api.deleteCategory).toHaveBeenCalledWith('c1')
    expect(names(page)).toEqual(['Zoo'])
    expect(page.get('[role="status"]').text()).toBe('Category deleted.')
  })

  it('removes the row on 404 and says "Already deleted"', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    api.deleteCategory.mockResolvedValue(failure({ kind: 'not-found', status: 404 }))

    await confirmDelete(page)

    expect(page.find('li').exists()).toBe(false)
    expect(page.get('[role="status"]').text()).toBe('Already deleted')
  })

  it('keeps the row and shows a fixed message on a server error', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    api.deleteCategory.mockResolvedValue(failure({ kind: 'server', status: 500 }))

    await confirmDelete(page)

    expect(names(page)).toEqual(['Food'])
    expect(page.get('li [role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
  })
})

describe('CategoriesView: review fixes', () => {
  const input = (page: Page) => page.get('#rename-c1-name')
  const edit = async (page: Page) => {
    await button(page, 'Rename').trigger('click')
    await flushPromises()
  }
  const submit = async (page: Page) => {
    await page.get('li form').trigger('submit')
    await flushPromises()
  }
  const conflict = failure({ kind: 'conflict', status: 409, detail: 'Changed.' })

  it('M1: the add form is unusable until the list has loaded', async () => {
    api.listCategories.mockReturnValueOnce(new Promise(() => {}))
    const pinia = createPinia()
    setActivePinia(pinia)
    const page = mount(CategoriesView, { global: { plugins: [pinia] } })
    await flushPromises()

    const submitButton = page.get('button[type="submit"]')
    await page.get('#category-name').setValue('Food')
    await page.get('form').trigger('submit')

    expect(submitButton.attributes('aria-disabled')).toBe('true')
    expect(api.createCategory).not.toHaveBeenCalled()
  })

  it('M2: while a delete is in flight, Delete is disabled, a second confirm sends nothing and Rename is blocked', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    api.deleteCategory.mockReturnValue(new Promise(() => {}))
    await button(page, 'Delete').trigger('click')
    await button(page, 'Confirm delete').trigger('click')
    await flushPromises()

    expect(button(page, 'Delete').attributes('aria-disabled')).toBe('true')
    await page.findComponent(ConfirmDeleteButton).vm.$emit('confirm')
    await button(page, 'Rename').trigger('click')
    await flushPromises()

    expect(api.deleteCategory).toHaveBeenCalledTimes(1)
    expect(button(page, 'Rename').attributes('aria-disabled')).toBe('true')
    expect(page.find('li form').exists()).toBe(false)
  })

  it('M3: after Reload, focus is on the name field and the reload is announced politely', async () => {
    const page = await render(listOf(cat('c1', 'Food', 3)))
    await edit(page)
    api.renameCategory.mockResolvedValueOnce(conflict)
    await input(page).setValue('Meals')
    await submit(page)
    api.listCategories.mockResolvedValueOnce(listOf(cat('c1', 'Groceries', 7)))

    await button(page, 'Reload').trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(input(page).element)
    expect(page.get('li [role="status"]').text()).toBe('Reloaded the latest name.')
  })

  it('M4: Reload for a category deleted elsewhere says so and moves focus to the heading', async () => {
    const page = await render(listOf(cat('c1', 'Food', 3)))
    await edit(page)
    api.renameCategory.mockResolvedValueOnce(conflict)
    await input(page).setValue('Meals')
    await submit(page)
    api.listCategories.mockResolvedValueOnce(listOf())

    await button(page, 'Reload').trigger('click')
    await flushPromises()

    expect(page.find('li').exists()).toBe(false)
    expect(page.get('[role="status"]').text()).toBe('This category no longer exists.')
    expect(document.activeElement).toBe(page.get('h1').element)
  })

  it('M5: Escape and Cancel do nothing while a save is pending, so a late 409 still shows', async () => {
    const page = await render(listOf(cat('c1', 'Food', 3)))
    await edit(page)
    let release!: (value: ApiResult<unknown>) => void
    api.renameCategory.mockReturnValue(new Promise((resolve) => (release = resolve)))
    await input(page).setValue('Meals')
    await submit(page)

    await input(page).trigger('keydown', { key: 'Escape' })
    await button(page, 'Cancel').trigger('click')
    expect(page.find('li form').exists()).toBe(true)
    release(conflict)
    await flushPromises()

    expect(page.get('li [role="alert"]').text()).toBe('Changed.')
    expect(button(page, 'Reload').exists()).toBe(true)
  })

  it('M5: a failed delete is shown in the row, never in the rename form', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    api.deleteCategory.mockResolvedValue(failure({ kind: 'server', status: 500 }))
    await button(page, 'Delete').trigger('click')
    await button(page, 'Confirm delete').trigger('click')
    await flushPromises()
    expect(page.find('li [role="alert"]').exists()).toBe(true)

    await edit(page)
    expect(page.find('li form [role="alert"]').exists()).toBe(false)
    await button(page, 'Cancel').trigger('click')
    await flushPromises()

    expect(page.find('li [role="alert"]').exists()).toBe(false)
  })

  it('M6: Rename, Delete, Confirm and the field name the category and start with the visible text', async () => {
    const page = await render(listOf(cat('c1', 'Food')))

    expect(button(page, 'Rename').attributes('aria-label')).toBe('Rename Food')
    expect(button(page, 'Delete').attributes('aria-label')).toBe('Delete Food')
    await button(page, 'Delete').trigger('click')
    expect(button(page, 'Confirm delete').attributes('aria-label')).toBe('Confirm delete Food')
    await button(page, 'Cancel').trigger('click')
    await edit(page)
    expect(input(page).attributes('aria-label')).toBe('New name for Food')
  })

  it('L7: the same notice twice in a row is announced again', async () => {
    const page = await render(listOf(cat('c1', 'Food'), cat('c2', 'Zoo')))
    api.deleteCategory.mockResolvedValue(ok(undefined, 204))
    const remove = async () => {
      await button(page, 'Delete').trigger('click')
      await button(page, 'Confirm delete').trigger('click')
      await flushPromises()
    }
    await remove()
    const added: Node[] = []
    const watcher = new MutationObserver((records) => {
      added.push(...records.flatMap((r) => [...r.addedNodes]))
    })
    watcher.observe(page.get('[role="status"]').element, { childList: true, subtree: true })

    await remove()

    watcher.disconnect()
    expect(added.length).toBeGreaterThan(0)
    expect(page.get('[role="status"]').text()).toBe('Category deleted.')
  })

  it('L8: Delete and Confirm point at the visible Uncategorized warning', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    const hintId = button(page, 'Delete').attributes('aria-describedby') ?? ''

    expect(page.get(`#${hintId}`).text()).toContain('its expenses become Uncategorized')
    await button(page, 'Delete').trigger('click')
    expect(button(page, 'Confirm delete').attributes('aria-describedby')).toBe(hintId)
  })

  it('L9: the Reload button is described by the unsaved-changes warning', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    await edit(page)
    api.renameCategory.mockResolvedValueOnce(conflict)
    await input(page).setValue('Meals')
    await submit(page)

    const id = button(page, 'Reload').attributes('aria-describedby') ?? ''

    expect(page.get(`#${id}`).text()).toContain('Your unsaved changes will be lost.')
  })

  it('L9: the field is read-only while a save is pending', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    await edit(page)
    api.renameCategory.mockReturnValue(new Promise(() => {}))
    await input(page).setValue('Meals')

    await submit(page)

    expect(input(page).attributes('readonly')).toBeDefined()
  })

  it('L9: Retry shows it is busy and ignores a second click', async () => {
    const page = await render(failure({ kind: 'network' }))
    api.listCategories.mockReset()
    api.listCategories.mockReturnValue(new Promise(() => {}))

    await button(page, 'Retry').trigger('click')
    await button(page, 'Retry…').trigger('click')

    expect(api.listCategories).toHaveBeenCalledTimes(1)
    expect(button(page, 'Retry…').attributes('aria-disabled')).toBe('true')
  })

  it('focus returns to Delete after a failed delete', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    api.deleteCategory.mockResolvedValue(failure({ kind: 'server', status: 500 }))
    await button(page, 'Delete').trigger('click')
    await button(page, 'Confirm delete').trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(button(page, 'Delete').element)
  })

  it('Enter in the add field before the list has loaded says to wait', async () => {
    api.listCategories.mockReturnValueOnce(new Promise(() => {}))
    const pinia = createPinia()
    setActivePinia(pinia)
    const page = mount(CategoriesView, { attachTo: document.body, global: { plugins: [pinia] } })
    await flushPromises()

    await page.get('#category-name').setValue('Food')
    await page.get('form').trigger('submit')
    await flushPromises()

    expect(page.get('[role="alert"]').text()).toBe('Wait for the list to load.')
    expect(api.createCategory).not.toHaveBeenCalled()
  })

  it('Cancel is aria-disabled while a save is pending', async () => {
    const page = await render(listOf(cat('c1', 'Food')))
    await edit(page)
    api.renameCategory.mockReturnValue(new Promise(() => {}))
    await input(page).setValue('Meals')
    expect(button(page, 'Cancel').attributes('aria-disabled')).toBeUndefined()

    await submit(page)

    expect(button(page, 'Cancel').attributes('aria-disabled')).toBe('true')
  })
})
