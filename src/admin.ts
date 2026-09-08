import './bootstrap';
import { registerGlobals } from './bootstrap';

import { createApp } from 'vue';
import App from '@components/admin/AdminMain.vue';

globalThis._m = {
  mode: 'admin',
} as any;

const app = createApp(App);
registerGlobals(app);
app.mount('#vue-content');

export default app;
