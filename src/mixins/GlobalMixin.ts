import { translate as t, translatePlural as n } from '@nextcloud/l10n';
import { c, initState } from '../services/utils';
import { defineComponent } from 'vue';

export default defineComponent({
  name: 'GlobalMixin',

  data: () => ({
    c,
    initState,
  }),

  computed: {
    routeIsBase(): boolean {
      return this.$route.name === 'timeline';
    },
    routeIsFavorites(): boolean {
      return this.$route.name === 'favorites';
    },
    routeIsVideos(): boolean {
      return this.$route.name === 'videos';
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
      return this.routeIsRecognize && this.$route.params.name === c.FACE_NULL;
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
