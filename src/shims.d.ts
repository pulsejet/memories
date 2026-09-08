declare module '*.css';
declare module '*.scss';

declare module '*.svg' {
  const content: string;
  export default content;
}

declare module 'vue-material-design-icons/*.vue' {
  import type { DefineComponent } from 'vue';
  const component: DefineComponent<{}, {}, any>;
  export default component;
}

declare module 'plyr/dist/plyr.mjs';
