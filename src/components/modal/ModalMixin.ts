import { defineComponent } from 'vue';

import * as utils from '../../services/utils';

export default defineComponent({
  name: 'ModalMixin',

  data: () => ({
    show: false,
  }),

  mounted() {
    utils.bus.on('memories:fragment:pop:modal', this.close);
  },

  beforeDestroy() {
    utils.bus.off('memories:fragment:pop:modal', this.close);
  },

  watch: {
    show(value: boolean, from: boolean) {
      utils.fragment.if(value, utils.fragment.types.modal);
    },
  },

  methods: {
    close() {
      if (this.show) {
        (<any>this.$refs.modal)?.close?.();
      }
    },
  },
});
