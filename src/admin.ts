import './bootstrap';

import Vue from 'vue';
import App from './components/admin/AdminMain.vue';

globalThis.mode = 'admin';

export default new Vue({
  el: '#vue-content',
  render: (h) => h(App),
});
