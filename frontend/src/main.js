import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './assets/app.css';

// Die Oberflaeche ist durchgehend deutsch (siehe labels.js).
document.documentElement.lang = 'de';

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.mount('#app');
