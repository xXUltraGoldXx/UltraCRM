import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './assets/app.css';

// The UI is entirely German throughout (see labels.js).
document.documentElement.lang = 'de';

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.mount('#app');
