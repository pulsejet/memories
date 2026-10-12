import './bootstrap';

import { createApp } from 'vue';
import App from './App.vue';

import router, { routes } from '@services/router';
import { bootstrapVideo } from '@services/video/bootstrap';
import * as nativex from '@native';

// CSS for components
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css';

// Initialize global memories object
globalThis._m = {
  mode: 'user',

  get route() {
    return router.currentRoute.value;
  },
  routes: routes,

  modals: {} as any,
  sidebar: {} as any,
  viewer: {} as any,
  video: {} as any,
};

// Initialize video client
bootstrapVideo();

// Register global components and plugins
const app = createApp(App);
app.use(router);

// Initialize NativeX globals
nativex.initialize();

app.mount('#content');

export default app;
