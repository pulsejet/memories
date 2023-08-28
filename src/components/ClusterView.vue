<template>
  <div v-if="noParams" class="container no-user-select cluster-view">
    <XLoadingIcon class="loading-icon centered" v-if="loading" />

    <TopMatter />

    <EmptyContent v-if="!items.length && !loading" />

    <ClusterGrid :items="items" :minCols="minCols" :maxSize="maxSize">
      <template #before>
        <DynamicTopMatter class="cv-dtm" ref="dtm" />
      </template>
    </ClusterGrid>
  </div>

  <Timeline v-else />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '../mixins/UserConfig';
import TopMatter from './top-matter/TopMatter.vue';
import ClusterGrid from './ClusterGrid.vue';
import Timeline from './Timeline.vue';
import EmptyContent from './top-matter/EmptyContent.vue';
import DynamicTopMatter from './top-matter/DynamicTopMatter.vue';

import * as dav from '../services/dav';
import * as utils from '../services/utils';

import type { ICluster } from '../types';

export default defineComponent({
  name: 'ClusterView',

  components: {
    TopMatter,
    ClusterGrid,
    Timeline,
    EmptyContent,
    DynamicTopMatter,
  },

  mixins: [UserConfig],

  data: () => ({
    items: [] as ICluster[],
    loading: 0,
  }),

  computed: {
    noParams() {
      return !this.$route.params.name && !this.$route.params.user;
    },

    minCols() {
      return this.routeIsAlbums ? 2 : 3;
    },

    maxSize() {
      return this.routeIsAlbums ? 250 : 180;
    },
  },

  mounted() {
    this.routeChange();
  },

  created() {
    utils.bus.on('memories:user-config-changed', this.routeChange);
  },

  beforeDestroy() {
    utils.bus.off('memories:user-config-changed', this.routeChange);
  },

  watch: {
    async $route() {
      this.routeChange();
    },
  },

  methods: {
    async routeChange() {
      try {
        this.items = [];
        this.loading++;

        await this.$nextTick();
        // @ts-ignore
        await this.$refs.dtm?.refresh?.();

        if (this.routeIsAlbums) {
          this.items = await dav.getAlbums(this.config.album_list_sort);
        } else if (this.routeIsTags) {
          this.items = await dav.getTags();
        } else if (this.routeIsPeople) {
          this.items = await dav.getFaceList(<any>this.$route.name);
        } else if (this.routeIsPlaces) {
          this.items = await dav.getPlaces();
        }
      } finally {
        this.loading--;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.container {
  height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  position: relative;

  .cv-dtm {
    margin-bottom: 5px;
  }
}
</style>
