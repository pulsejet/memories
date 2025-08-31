import './bootstrap';

import Vue from 'vue';
import App from './App.vue';
import router, { routes } from './router';

// Global components
import XImg from '@components/frame/XImg.vue';
import VueVirtualScroller from 'vue-virtual-scroller';

// CSS for components
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css';
import '@nextcloud/dialogs/style.css';

// Initialize global memories object
globalThis._m = {
  mode: 'user',

  get route() {
    return router.currentRoute;
  },
  router: router,
  routes: routes,

  modals: {} as any,
  selectionManager: {} as any,
  sidebar: {} as any,
  viewer: {} as any,
  video: {} as any,

  window: {
    innerWidth: window.innerWidth,
    innerHeight: window.innerHeight,
  },
};

// Generate client id for this instance
// Does not need to be cryptographically secure
_m.video.clientId = Math.random().toString(36).substring(2, 15).padEnd(12, '0');
_m.video.clientIdPersistent = localStorage.getItem('videoClientIdPersistent') ?? _m.video.clientId;
localStorage.setItem('videoClientIdPersistent', _m.video.clientIdPersistent);

// Register global components and plugins
Vue.use(VueVirtualScroller);
Vue.component('XImg', XImg);

export default new Vue({
  el: '#content',
  router,
  render: (h) => h(App),
});
