import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createWebHistory } from 'vue-router'

import App from './App.vue'
import './assets/main.css'
import { installErrorHandler } from './lib/appError'
import { installBfcacheGuard } from './lib/bfcache'
import { installSessionChannel } from './lib/sessionChannel'
import { createAppRouter } from './router'
import { installSessionExpiry } from './router/expiry'
import { useSessionStore } from './stores/session'

// Back after sign-out must not show the previous user's frozen page.
installBfcacheGuard(window)

const app = createApp(App)
const router = createAppRouter(createWebHistory(import.meta.env.BASE_URL))

// Unexpected errors show a generic banner and are logged nowhere (plan phase-4 C16).
installErrorHandler(app, router)
const pinia = createPinia()
app.use(pinia)
// Exactly once, here: never from a guard or a component (plan phase-4 C5).
installSessionExpiry(router)
// Follows a sign-out or a different sign-in made in another tab (finding M1). One channel for the life of the page.
installSessionChannel(() => useSessionStore(pinia).user?.id)
app.use(router)

app.mount('#app')
