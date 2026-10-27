import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createWebHistory } from 'vue-router'

import App from './App.vue'
import './assets/main.css'
import { installErrorHandler } from './lib/appError'
import { installBfcacheGuard } from './lib/bfcache'
import { createAppRouter } from './router'
import { installSessionExpiry } from './router/expiry'

// Back after sign-out must not show the previous user's frozen page.
installBfcacheGuard(window)

const app = createApp(App)
const router = createAppRouter(createWebHistory(import.meta.env.BASE_URL))

// Unexpected errors show a generic banner and are logged nowhere (plan phase-4 C16).
installErrorHandler(app, router)
app.use(createPinia())
// Exactly once, here: never from a guard or a component (plan phase-4 C5).
installSessionExpiry(router)
app.use(router)

app.mount('#app')
