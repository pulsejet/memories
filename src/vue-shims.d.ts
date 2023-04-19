declare module "*.vue" {
  import type { defineComponent } from "vue";
  const Component: ReturnType<typeof defineComponent>;
  export default Component;
}

declare module "*.svg" {
  const content: string;
  export default content;
}
