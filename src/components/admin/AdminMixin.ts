import { defineComponent, type PropType } from 'vue';
import axios from '@nextcloud/axios';

const NcCheckboxRadioSwitch = () => import('@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js');
const NcNoteCard = () => import('@nextcloud/vue/dist/Components/NcNoteCard.js');
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import type { ISystemStatus, ISystemConfig, IBinaryStatus } from './AdminTypes';
import type { IConfig } from '@typings';

export default defineComponent({
  name: 'AdminMixin',

  components: {
    NcCheckboxRadioSwitch,
    NcNoteCard,
    NcTextField,
    NcButton,
  },

  props: {
    status: {
      type: Object as PropType<ISystemStatus | null>,
      default: null,
      required: false,
    },
    config: {
      type: Object as PropType<ISystemConfig>,
      required: true,
    },
    sconfig: {
      type: Object as PropType<IConfig>,
      required: true,
    },
  },

  emits: {
    update: (key: keyof ISystemConfig, value: any) => true,
  },

  methods: {
    update(key: keyof ISystemConfig, value: any = null) {
      this.$emit('update', key, value);
    },

    binaryStatus(name: string, status: IBinaryStatus): string {
      const noescape = {
        escape: false,
        sanitize: false,
      };
      if (status === 'ok') {
        return this.t('memories', '{name} binary exists and is executable.', {
          name,
        });
      } else if (status === 'not_found') {
        return this.t('memories', '{name} binary not found.', { name });
      } else if (status === 'not_executable') {
        return this.t('memories', '{name} binary is not executable.', {
          name,
        });
      } else if (status.startsWith('test_fail')) {
        return this.t(
          'memories',
          '{name} failed test: {info}.',
          {
            name,
            info: status.substring(10),
          },
          0,
          noescape,
        );
      } else if (status.startsWith('test_ok')) {
        return this.t(
          'memories',
          '{name} binary exists and is usable ({info}).',
          {
            name,
            info: status.substring(8),
          },
          0,
          noescape,
        );
      } else {
        return this.t('memories', '{name} binary status: {status}.', {
          name,
          status,
        });
      }
    },

    binaryStatusType(status: IBinaryStatus, critical = true): 'success' | 'warning' | 'error' {
      if (this.binaryStatusOk(status)) {
        return 'success';
      } else if (status === 'not_found' || status === 'not_executable' || status.startsWith('test_fail')) {
        return critical ? 'error' : 'warning';
      } else {
        return 'warning';
      }
    },

    binaryStatusOk(status: IBinaryStatus): boolean {
      return status === 'ok' || status.startsWith('test_ok');
    },
  },

  computed: {
    requestToken() {
      return (<any>axios.defaults.headers).requesttoken;
    },

    actionToken() {
      return this.status?.action_token || '';
    },

    /** Reverse of memories.vod.disable, unfortunately */
    enableTranscoding: {
      get() {
        return !this.config['memories.vod.disable'];
      },
      set(value: boolean) {
        this.config['memories.vod.disable'] = !value;
      },
    },
  },
});
