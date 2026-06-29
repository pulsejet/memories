<template>
  <div
    class="top-matter-container timeline-scroller-gap"
    :class="{
      'dynamic-visible': dynamicVisible,
    }"
  >
    <component v-if="currentmatter" :is="currentmatter" />

    <div v-if="!currentmatter" class="top-matter-date-only">
      <div class="right-actions">
        <GoToDateMenuItem />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import FolderTopMatter from './FolderTopMatter.vue';
import ClusterTopMatter from './ClusterTopMatter.vue';
import FaceTopMatter from './FaceTopMatter.vue';
import AlbumTopMatter from './AlbumTopMatter.vue';
import PlacesTopMatter from './PlacesTopMatter.vue';

import GoToDateMenuItem from '@components/header/GoToDateMenuItem.vue';

import * as utils from '@services/utils';

export default defineComponent({
  name: 'TopMatter',
  components: {
    FolderTopMatter,
    ClusterTopMatter,
    FaceTopMatter,
    AlbumTopMatter,
    GoToDateMenuItem,
  },

  data: () => ({
    dynamicVisible: true,
  }),

  mounted() {
    utils.bus.on('memories.recycler.scroll', this.onRecyclerScroll);
  },

  beforeUnmount() {
    utils.bus.off('memories.recycler.scroll', this.onRecyclerScroll);
  },

  computed: {
    currentmatter() {
      switch (this.$route.name) {
        case _m.routes.Folders.name:
          return FolderTopMatter;
        case _m.routes.FolderShare.name:
          return this.initstate.shareType === 'folder' ? FolderTopMatter : null;
        case _m.routes.Albums.name:
          return AlbumTopMatter;
        case _m.routes.Places.name:
          return PlacesTopMatter;
        case _m.routes.Tags.name:
          return ClusterTopMatter;
        case _m.routes.Recognize.name:
        case _m.routes.FaceRecognition.name:
          return FaceTopMatter;
        default:
          return null;
      }
    },
  },

  methods: {
    onRecyclerScroll({ dynTopMatterVisible }: utils.BusEvent['memories.recycler.scroll']) {
      this.dynamicVisible = dynTopMatterVisible;
    },
  },
});
</script>

<style lang="scss" scoped>
.top-matter-container {
  position: relative;
  z-index: 200; // above scroller, below top-bar
  padding: 2px 0;
  background-color: var(--color-main-background);
  transition: box-shadow 0.2s ease-in-out;

  &:not(.dynamic-visible) {
    box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.1);
  }

  // Hide shadow if inside cluster view
  .cluster-view & {
    box-shadow: none;
  }

  @media (max-width: 768px) {
    padding-left: 10px; // extra space visual
  }

  > div {
    display: flex;
    vertical-align: middle;
  }

  :deep .name {
    overflow: hidden;
    text-overflow: ellipsis;
    padding-left: 10px;
    font-size: 1.3em;
    font-weight: 400;
    line-height: 42px;
    white-space: nowrap;
    vertical-align: top;
    flex-grow: 1;
  }

  :deep button + .name {
    padding-left: 0;
  }

  :deep .right-actions {
    margin-right: 12px;
    z-index: 50;
    @media (max-width: 768px) {
      margin-right: 10px;
    }

    /**
     * Hide the actions when the selection manager is open.
     * Having two action bars is confusing.
     */
    .memories-timeline:has(.memories-top-bar) & {
      visibility: hidden;
    }

    span {
      cursor: pointer;
    }
  }

  .top-matter-date-only {
    justify-content: flex-end;
  }

  :deep button {
    display: inline-block;
  }
}
</style>
