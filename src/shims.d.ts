declare module '*.svg' {
  const content: string;
  export default content;
}

declare module '*.vue';

// External components cannot be imported with .vue extension
declare module '@nextcloud/vue/dist/Components/*' {
  import type { defineComponent } from 'vue';
  const Component: ReturnType<typeof defineComponent>;
  export default Component;
}

declare module 'vue-virtual-scroller';
