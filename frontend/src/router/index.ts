import { createRouter, type RouteRecordRaw, type RouterHistory } from 'vue-router'

import CategoriesView from '@/views/CategoriesView.vue'
import ExpenseFormView from '@/views/ExpenseFormView.vue'
import ExpensesView from '@/views/ExpensesView.vue'
import LoginView from '@/views/LoginView.vue'
import NotFoundView from '@/views/NotFoundView.vue'
import RegisterView from '@/views/RegisterView.vue'
import { installGuards } from './guards'

// A lower-case version 4 uuid, the only id the API issues. A malformed id is a 404 in the browser with no request.
// No capturing group: vue-router's path syntax does not allow one inside a parameter.
const UUID_V4 = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}'

// `meta.access` is 'private' when absent (see meta.d.ts). Titles are fixed text, never user data.
const routes: RouteRecordRaw[] = [
  { path: '/', redirect: '/expenses' },
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { title: 'Sign in', access: 'guest' },
  },
  {
    path: '/register',
    name: 'register',
    component: RegisterView,
    meta: { title: 'Create account', access: 'guest' },
  },
  { path: '/expenses', name: 'expenses', component: ExpensesView, meta: { title: 'Expenses' } },
  {
    path: '/expenses/new',
    name: 'expense-new',
    component: ExpenseFormView,
    meta: { title: 'New expense' },
  },
  {
    path: `/expenses/:id(${UUID_V4})`,
    name: 'expense-edit',
    component: ExpenseFormView,
    meta: { title: 'Edit expense' },
  },
  {
    path: '/categories',
    name: 'categories',
    component: CategoriesView,
    meta: { title: 'Categories' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: NotFoundView,
    meta: { title: 'Page not found', access: 'public' },
  },
]

/**
 * A router per app (and per test): the history is passed in, so tests use an in-memory one and never share state.
 * `sensitive: true` because matching is case-insensitive by default, which would let `/EXPENSES` and an upper-case id
 * reach the private routes (plan phase-4 Step 0 R1).
 */
export function createAppRouter(history: RouterHistory) {
  const router = createRouter({ history, routes, sensitive: true })
  installGuards(router)
  return router
}
