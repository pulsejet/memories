import { defineComponent } from 'vue';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'ModalMixin',

  data: () => ({
    show: false,
    _closing: null as null | ((value: unknown) => void),
  }),

  mounted() {
    utils.bus.on('memories:fragment:pop:modal', this.close);
  },

  beforeDestroy() {
    utils.bus.off('memories:fragment:pop:modal', this.close);
  },

  watch: {
    show(value: boolean) {
      utils.fragment.if(value, utils.fragment.types.modal);

      if (!value) {
        this._closing?.(null);
        this._closing = null;
      }
    },
  },

  methods: {
    async close() {
      if (this.show && !this._closing) {
        // pop the fragment immediately
        await utils.fragment.pop(utils.fragment.types.modal);

        // close the modal with animation
        (<any>this.$refs.modal)?.close?.();

        // wait for transition to end
        await new Promise((resolve) => (this._closing = resolve));
      }
    },
  },
});
