<template>
  <div ref="outer" class="memories-searchbar">
    <NcPopover :shown="shown" :focus-trap="false" @after-hide="pHidden = true">
      <template #trigger="{ attrs }">
        <div v-bind="attrs">
          <NcTextField
            class="text-field"
            :value.sync="prompt"
            :label-outside="true"
            :label="t('memories', 'Search your photos …')"
            :placeholder="t('memories', 'Search your photos …')"
          >
            <MagnifyIcon :size="16" />
          </NcTextField>
        </div>
      </template>

      <div class="searchbar-results">
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
          <router-link class="cluster" :to="clusterTarget(cluster)" @click.native="reset()">
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
import { defineComponent } from 'vue';

const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');
const NcPopover = () => import('@nextcloud/vue/dist/Components/NcPopover.js');

import UserConfig from '@mixins/UserConfig';

import * as dav from '@services/dav';

import Fuse from 'fuse.js';

import MagnifyIcon from 'vue-material-design-icons/Magnify.vue';
import AlbumIcon from 'vue-material-design-icons/ImageAlbum.vue';
import LocationIcon from 'vue-material-design-icons/MapMarker.vue';
import TagIcon from 'vue-material-design-icons/Tag.vue';

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
  },

  mixins: [UserConfig],

  data: () => ({
    prompt: String(),

    // Popover can be hidden by clicking outside and
    // so subsequent changes to prompt do not trigger
    // it to show again. This flag is used to force it.
    pHidden: false,

    clusters: null as ICluster[] | null,
    clustersLoad: false,
    clusterIs: dav.clusterIs,
    clusterPreview: dav.getClusterPreview,
    clusterTarget: dav.getClusterLinkTarget,
  }),

  mounted() {
    // Add mutation observer to disable box shadow on input
    // This is really unfortunate since the input uses !important
    // to add a ugly white box shadow on hover and focus.
    // Hopefully that changes at some point.
    let observer: MutationObserver;
    observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node instanceof HTMLElement) {
            const input = node.querySelector<HTMLInputElement>('input[type="text"]');
            if (input) {
              input?.style.setProperty('box-shadow', 'none', 'important');
              observer.disconnect();
            }
          }
        });
      });
    });
    observer.observe(this.refs.outer, { childList: true, subtree: true });
  },

  computed: {
    refs() {
      return {
        outer: this.$refs.outer as HTMLDivElement,
      };
    },

    shown() {
      return !this.pHidden && this.clustersResult.length > 0;
    },

    clustersResult(): ICluster[] {
      if (!this.prompt) return [];
      return this.clustersFuse.search(this.prompt, { limit: 6 }).map((r) => r.item);
    },

    clustersFuse() {
      return new Fuse(this.clusters ?? [], { keys: ['name', 'display_name'], threshold: 0.3 });
    },
  },

  watch: {
    prompt(val: string) {
      this.pHidden = false;
      if (!val) return;
      this.load(); // load clusters
    },
  },

  methods: {
    reset() {
      this.prompt = String();
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
  },
});
</script>

<style lang="scss" scoped>
header .memories-searchbar .text-field {
  margin: 5px 0 !important;
  > * {
    margin: 0 !important;
  }
  :deep input {
    // header is 50px; 5px gap on each side
    height: 40px !important;
    border: none !important;
    background-color: color-mix(in srgb, var(--color-primary-text) 12%, transparent);
    backdrop-filter: blur(2px);
  }
  :deep .input-field__icon {
    height: 46px !important; // hack to center the icon
  }
  :deep *,
  :deep input::placeholder {
    color: var(--color-primary-text);
  }
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
