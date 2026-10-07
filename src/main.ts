import './bootstrap';

import { createApp } from 'vue';
import App from './App.vue';
import router, { routes } from '@services/router';
import * as nativex from '@native';

// Global components
import VueVirtualScroller from 'vue-virtual-scroller';

// CSS for components
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css';

// Initialize global memories object
globalThis._m = {
  mode: 'user',

  get route() {
    return router.currentRoute.value;
  },
  router: router,
  routes: routes,

  modals: {} as any,
  sidebar: {} as any,
  viewer: {} as any,
  video: {} as any,
};

// Generate client id for this instance
// Does not need to be cryptographically secure
_m.video.clientId = Math.random().toString(36).slice(2, 15).padEnd(12, '0');
_m.video.clientIdPersistent = localStorage.getItem('videoClientIdPersistent') ?? _m.video.clientId;
localStorage.setItem('videoClientIdPersistent', _m.video.clientIdPersistent);

// Register global components and plugins
const app = createApp(App);
app.use(router);
app.use(VueVirtualScroller);

// Initialize NativeX globals
nativex.initialize();

app.mount('#content');

export default app;
