import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { constants } from '../services/Utils';
import { loadState } from '@nextcloud/initial-state';
import { defineComponent } from 'vue';

export default defineComponent({
  name: 'GlobalMixin',

  data: () => ({
    ...constants,

    state_noDownload: loadState('memories', 'no_download', false) !== false,
  }),

  computed: {
    routeIsBase(): boolean {
      return this.$route.name === 'timeline';
    },
    routeIsPeople(): boolean {
      return ['recognize', 'facerecognition'].includes(<string>this.$route.name);
    },
    routeIsArchive(): boolean {
      return this.$route.name === 'archive';
    },
    routeIsFolders(): boolean {
      return this.$route.name === 'folders';
    },
    routeIsAlbums(): boolean {
      return this.$route.name === 'albums';
    },
    routeIsPublic(): boolean {
      return this.$route.name?.endsWith('-share') ?? false;
    },
  },

  methods: {
    t,
    n,
  },
});
