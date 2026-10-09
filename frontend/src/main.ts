import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createWebHistory } from 'vue-router'

import App from './App.vue'
import { createAppRouter } from './router'

const app = createApp(App)

app.use(createPinia())
app.use(createAppRouter(createWebHistory(import.meta.env.BASE_URL)))

app.mount('#app')
