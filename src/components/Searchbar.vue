<template>
  <div class="search-overlay">
    <div class="search-bar">
      <NcPopover :shown="shown" :focus-trap="false">
        <template #trigger="{ attrs }">
          <NcTextField
            v-bind="attrs"
            :autofocus="true"
            :value.sync="prompt"
            :label-outside="true"
            :label="t('memories', 'Search photos and albums')"
            :placeholder="t('memories', 'Search photos and albums')"
          >
            <MagnifyIcon :size="16" />
          </NcTextField>
        </template>

        <div class="searchbar-results">
          <div class="row" v-for="cluster of clustersResult" tabindex="1">
            <div class="icon">
              <AlbumIcon v-if="clusterIs.album(cluster)" :size="22" />
              <LocationIcon v-else-if="clusterIs.place(cluster)" :size="22" />
              <TagIcon v-else-if="clusterIs.tag(cluster)" :size="22" />
              <XImg v-else-if="clusterIs.face(cluster)" :src="clusterPreview(cluster)" class="preview-image" />
              <MagnifyIcon v-else :size="22" />
            </div>

            {{ cluster.display_name ?? cluster.name }}
          </div>
        </div>
      </NcPopover>
    </div>
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
import XImg from './frame/XImg.vue';

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
    showPopover: false,

    clusters: [] as ICluster[],
    clustersLoad: false,
    clusterIs: dav.clusterIs,
    clusterPreview: dav.getClusterPreview,
  }),

  computed: {
    shown(): boolean {
      return this.clustersResult.length > 0;
    },

    clustersResult(): ICluster[] {
      return this.clustersFuse.search(this.prompt, { limit: 6 }).map((r) => r.item);
    },

    clustersFuse() {
      return new Fuse(this.clusters, { keys: ['name', 'display_name'], threshold: 0.3 });
    },
  },

  watch: {
    prompt(val: string) {
      if (!val) return;
      this.load(); // load clusters
    },
  },

  methods: {
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
.search-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background-color: rgba(0, 0, 0, 0.85);
  z-index: 100000000;
}

.search-bar {
  position: relative;
  margin: 10vh auto;
  width: 400px;
  max-width: calc(100vw - 20px);
}

.searchbar-results {
  padding: 10px 0;
  width: 400px;
  max-width: calc(100vw - 20px);

  .row {
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
