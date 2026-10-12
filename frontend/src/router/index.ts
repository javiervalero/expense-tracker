import { createRouter, type RouterHistory } from 'vue-router'

import HomeView from '@/views/HomeView.vue'

/** A router per app (and per test): the history is passed in, so tests use an in-memory one and never share state. */
export function createAppRouter(history: RouterHistory) {
  return createRouter({
    history,
    routes: [{ path: '/', name: 'home', component: HomeView }],
  })
}
