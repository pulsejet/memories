import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { c, initState } from '../services/utils';
import { defineComponent } from 'vue';

export default defineComponent({
  name: 'GlobalMixin',

  data: () => ({
    c,
    initState,
  }),

  methods: {
    t,
    n,
  },
});
