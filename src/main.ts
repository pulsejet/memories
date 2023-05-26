import './bootstrap';

import Vue from 'vue';
import App from './App.vue';
import router from './router';

globalThis.mode = 'user';

export default new Vue({
  el: '#content',
  router,
  render: (h) => h(App),
});
