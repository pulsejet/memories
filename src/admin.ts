import './bootstrap';

import Vue from 'vue';
import App from '@components/admin/AdminMain.vue';

globalThis._m = {
  mode: 'admin',
} as any;

export default new Vue({
  el: '#vue-content',
  render: (h) => h(App),
});
