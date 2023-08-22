import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { constants } from '../services/utils';
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
    routeIsFolders(): boolean {
      return this.$route.name === 'folders';
    },
    routeIsAlbums(): boolean {
      return this.$route.name === 'albums';
    },
    routeIsPeople(): boolean {
      return ['recognize', 'facerecognition'].includes(<string>this.$route.name);
    },
    routeIsRecognize(): boolean {
      return this.$route.name === 'recognize';
    },
    routeIsRecognizeUnassigned(): boolean {
      return this.routeIsRecognize && this.$route.params.name === constants.FACE_NULL;
    },
    routeIsFaceRecognition(): boolean {
      return this.$route.name === 'facerecognition';
    },
    routeIsArchive(): boolean {
      return this.$route.name === 'archive';
    },
    routeIsPlaces(): boolean {
      return this.$route.name === 'places';
    },
    routeIsMap(): boolean {
      return this.$route.name === 'map';
    },
    routeIsTags(): boolean {
      return this.$route.name === 'tags';
    },
    routeIsExplore(): boolean {
      return this.$route.name === 'explore';
    },
    routeIsAlbumShare(): boolean {
      return this.$route.name === 'album-share';
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
