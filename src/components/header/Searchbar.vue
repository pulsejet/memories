<template>
  <div ref="outer" class="memories-searchbar">
    <NcPopover :shown="shown" :no-focus-trap="true" @after-hide="pHidden = true">
      <template #trigger="{ attrs }">
        <div v-bind="attrs">
          <NcTextField
            ref="textField"
            class="text-field"
            v-model="prompt"
            auto-complete="off"
            :label-outside="true"
            :label="t('memories', 'Search your photos …')"
            :placeholder="t('memories', 'Search your photos …')"
            @keydown.enter="openSearch"
          >
            <template #icon>
              <MagnifyIcon :size="16" />
            </template>
          </NcTextField>
        </div>
      </template>

      <div class="searchbar-results" v-if="!isLensLive">
        <div v-if="showLensEntry" class="cluster" @click="openSearch">
          <div class="icon">
            <MagnifyIcon :size="22" />
          </div>
          {{ lensEntryText }}
        </div>

        <div class="empty" v-if="prompt.length === 0">
          {{ t('memories', 'Start typing to find photos and albums') }}
        </div>
        <div class="empty" v-else-if="!clusters && clustersLoad">
          <XLoadingIcon class="fill-block" />
        </div>
        <div class="empty" v-else-if="clustersResult.length === 0">
          {{ t('memories', 'No results found') }}
        </div>

        <template v-for="cluster of clustersResult">
          <router-link class="cluster" :to="clusterTarget(cluster)" @click.native="select()">
            <div class="icon">
              <AlbumIcon v-if="clusterIs.album(cluster)" :size="22" />
              <LocationIcon v-else-if="clusterIs.place(cluster)" :size="22" />
              <TagIcon v-else-if="clusterIs.tag(cluster)" :size="22" />
              <XImg v-else-if="clusterIs.face(cluster)" :src="clusterPreview(cluster)" class="preview-image" />
              <MagnifyIcon v-else :size="22" />
            </div>

            {{ cluster.display_name ?? cluster.name }}
          </router-link>
        </template>
      </div>
    </NcPopover>
  </div>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));
const NcPopover = defineAsyncComponent(() => import('@nextcloud/vue/components/NcPopover'));

import UserConfig from '@mixins/UserConfig';

import * as dav from '@services/dav';
import * as lens from '@services/lens';
import * as utils from '@services/utils';

import Fuse from 'fuse.js';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';
import XImg from '@components/frame/XImg.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import type { ICluster } from '@typings';

export default defineComponent({
  name: 'Searchbar',

  components: {
    NcTextField,
    NcPopover,
    MagnifyIcon,
    AlbumIcon,
    LocationIcon,
    TagIcon,
    XImg,
    XLoadingIcon,
  },

  mixins: [UserConfig],

  emits: {
    select: () => true,
  },

  props: {
    autoFocus: {
      type: Boolean,
      default: false,
    },
  },

  data: () => ({
    prompt: String(),

    // Popover can be hidden by clicking outside and
    // so subsequent changes to prompt do not trigger
    // it to show again. This flag is used to force it.
    pHidden: false,

    // Pending live lens navigation (debounced)
    lensTimer: null as number | null,

    clusters: null as ICluster[] | null,
    clustersLoad: false,
    clusterIs: dav.clusterIs,
    clusterPreview: dav.getClusterPreview,
    clusterTarget: dav.getClusterLinkTarget,
  }),

  mounted() {
    this.syncPromptFromRoute();
    setTimeout(() => {
      this.syncPromptFromRoute();
      if (this.autoFocus) {
        (<any>this.$refs.textField)?.focus();
      }
    }, 100); // wait for opacity transition
  },

  computed: {
    refs() {
      return {
        outer: this.$refs.outer as HTMLDivElement,
      };
    },

    shown() {
      // Live search mode navigates directly; no popover needed
      return !this.pHidden && !!this.prompt.length;
    },

    clustersResult(): ICluster[] {
      if (!this.prompt) return [];
      return this.clustersFuse.search(this.prompt, { limit: 6 }).map((r) => r.item);
    },

    clustersFuse() {
      return new Fuse(this.clusters ?? [], { keys: ['name', 'display_name'], threshold: 0.3 });
    },

    /** Live lens search hijacks typing only on desktop timeline/search views */
    isLensLive(): boolean {
      return (this.routeIsBase || this.routeIsSearch) && !utils.isMobile();
    },

    /** Explicit lens entry for anywhere live search does not apply */
    showLensEntry(): boolean {
      return !!this.prompt && !this.isLensLive;
    },

    lensEntryText(): string {
      return this.t('memories', 'Find photos matching “{query}”', { query: this.prompt });
    },
  },

  watch: {
    prompt(val: string) {
      this.pHidden = false;
      if (val) {
        this.load(); // load clusters
      }

      // Queue lens search if route changed.
      if (lens.routeQueryText(this.$route.query.q) !== val) {
        this.queueLensSearch();
      }
    },
  },

  methods: {
    select() {
      this.prompt = String();
      this.$emit('select');
    },

    async load() {
      // Load all clusters that we can search in
      if (!this.clustersLoad) {
        this.clustersLoad = true;

        const noop = new Promise<ICluster[]>((r) => r([]));

        const results = await Promise.allSettled([
          this.config.recognize_enabled ? dav.getFaceList('recognize') : noop,
          this.config.facerecognition_enabled ? dav.getFaceList('facerecognition') : noop,
          this.config.places_gis > 0 ? dav.getPlaces({ covers: 0 }) : noop,
          this.config.systemtags_enabled ? dav.getTags() : noop,
          this.config.albums_enabled ? dav.getAlbums() : noop,
        ]);

        // Ignore all errors and flatten
        this.clusters = results
          .flatMap((r) => (r.status === 'fulfilled' ? r.value : []))
          .filter((c) => !!(c.name || c.display_name));
      }
    },

    /** Mirror ?q= into the box when on the search view (e.g. direct open). */
    syncPromptFromRoute() {
      if (!this.routeIsSearch) return;
      const q = lens.routeQueryText(this.$route.query.q);
      if (q !== this.prompt) this.prompt = q;
    },

    /** Open the search view for the current prompt */
    openSearch() {
      const q = this.prompt.trim();
      if (!q || this.isLensLive) return;
      window.clearTimeout(this.lensTimer ?? 0);
      this.lensTimer = null;
      this.$router.push({ name: 'search', query: { q } });
      this.prompt = q;
      this.pHidden = true;
      this.$emit('select');
    },

    /** Live lens search on desktop timeline/search views */
    queueLensSearch() {
      if (!this.isLensLive) return;
      utils.setRenewingTimeout(this, 'lensTimer', this.routeToLens, 500);
    },

    /** Run the pending live lens navigation */
    routeToLens() {
      if (!this.prompt) {
        if (!this.routeIsBase) {
          this.$router.replace({ name: 'timeline' });
        }
      } else {
        this.$router.replace({
          name: 'search',
          query: {
            ...this.$route.query,
            q: this.prompt,
          },
        });
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.memories-searchbar .text-field {
  width: 220px;
  max-width: calc(100% - 20px);
  margin: 0 auto;

  @media (max-width: 768px) {
    width: 400px;
  }

  header & {
    --searchbar-color: var(--color-background-plain-text, var(--color-primary-text));
  }

  #mobile-header &,
  .explore-outer & {
    --searchbar-color: var(--color-main-text);
  }

  // Size styles for header only
  header &,
  #mobile-header & {
    max-width: 100%;
    // header is 50px; 5px gap on each side
    margin: 5px 0 !important;
    --default-clickable-area: 40px;
  }

  // Styling for flat input
  header &,
  #mobile-header &,
  .explore-outer & {
    // Remove padding from text bar
    --border-width-input-focused: 0px;

    :deep(input[type='text']) {
      border: none !important;
      background-color: color-mix(in srgb, var(--searchbar-color) 12%, transparent);
      backdrop-filter: blur(2px);

      // input[type='text'] has an ugly border on hover and focus
      // with !important, we need to override it with more specificity
      box-shadow: none !important;

      // Prevent jumping text on hover / focus
      --input-border-width-offset: 0px;
    }

    :deep(*),
    :deep(input[type='text']::placeholder) {
      color: var(--searchbar-color);
    }
  }

  .explore-outer & {
    width: 100%;
  }
}

.memories-searchbar.full-width .text-field {
  width: 100%;
}

.searchbar-results {
  padding: 10px 0;
  width: 400px;
  max-width: calc(100vw - 20px);

  .empty {
    text-align: center;
    padding: 8px 14px;

    &:has(.loading-icon) {
      margin: 12px;
    }
  }

  .cluster {
    display: block;
    padding: 8px 14px;
    cursor: pointer;
    text-overflow: ellipsis;
    white-space: nowrap;
    overflow: hidden;

    &:hover {
      background-color: var(--color-background-hover);
    }

    .icon {
      display: inline-block;
      transform: translateY(2px);
      width: 28px;

      > .material-design-icon {
        display: inline-block;
        vertical-align: middle;
      }

      .preview-image {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        vertical-align: top;
      }
    }
  }
}
</style>
