import { createApp } from 'vue'
import './styles/tokens.css'
import './style.css'
import App from './App.vue'
import router from './router'
import { startDomTranslation } from './stores/domTranslation'

createApp(App).use(router).mount('#app')
startDomTranslation()
