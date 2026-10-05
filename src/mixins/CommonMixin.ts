import { defineComponent } from 'vue';

export default defineComponent({
  name: 'CommonMixin',

  computed: {
    windowWidth(): number {
      return _m.window.innerWidth;
    },

    windowHeight(): number {
      return _m.window.innerHeight;
    },

    windowWidthIsMobile(): boolean {
      return _m.window.innerWidth <= 768;
    },

    windowDims(): { width: number; height: number } {
      return {
        width: _m.window.innerWidth,
        height: _m.window.innerHeight,
      };
    },
  },
});
