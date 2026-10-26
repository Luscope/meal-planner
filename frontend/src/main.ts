import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useThemeStore } from './stores/theme'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// Applied as early as possible (before mount) to avoid a flash of the wrong
// theme on load.
useThemeStore()

app.mount('#app')
