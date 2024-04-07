declare module '*.svg' {
  const content: string;
  export default content;
}

// Vue components can be imported with .vue extension
declare module '*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<{}, {}, any>;
  export default component;
}

// External components cannot be imported with .vue extension
declare module '@nextcloud/vue/dist/Components/*.js' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<{}, {}, any>;
  export default component;
}

declare module 'vue-virtual-scroller';

declare module 'plyr/dist/plyr.mjs';
