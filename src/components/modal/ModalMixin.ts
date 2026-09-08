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

  beforeUnmount() {
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
        // Claim the closing synchronously. Concurrent close() calls, e.g. from
        // fragment pop events emitted by our own pop() below, must be ignored.
        // Otherwise duplicate pop() calls race duplicate history navigations
        // against each other and the modal never closes.
        let resolveClosing!: (value: unknown) => void;
        const closing = new Promise<unknown>((resolve) => (resolveClosing = resolve));
        this._closing = resolveClosing;

        try {
          // pop the fragment immediately
          await utils.fragment.pop(utils.fragment.types.modal);

          // close the modal with animation
          (<any>this.$refs.modal)?.close?.();

          // wait for transition to end (resolved by the show watcher)
          await closing;
        } catch (e) {
          // Never leave a stale claim behind: a failed close must stay retryable.
          if (this._closing === resolveClosing) this._closing = null;
          throw e;
        }
      }
    },
  },
});
