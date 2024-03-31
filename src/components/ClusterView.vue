<template>
  <div v-if="noParams" class="container no-user-select cluster-view">
    <XLoadingIcon class="loading-icon centered" v-if="loading" />

    <TopMatter />

    <EmptyContent v-if="!items.length && !loading" />

    <ClusterGrid :items="items" :minCols="minCols" :maxSize="maxSize" :focus="true">
      <template #before>
        <DynamicTopMatter class="cv-dtm" ref="dtm" />
      </template>
    </ClusterGrid>
  </div>

  <Timeline v-else />
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import type { Route } from 'vue-router';

import UserConfig from '@mixins/UserConfig';
import TopMatter from '@components/top-matter/TopMatter.vue';
import ClusterGrid from '@components/ClusterGrid.vue';
import Timeline from '@components/Timeline.vue';
import EmptyContent from '@components/top-matter/EmptyContent.vue';
import DynamicTopMatter from '@components/top-matter/DynamicTopMatter.vue';

import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { ICluster } from '@typings';

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
    refs() {
      return this.$refs as {
        dtm?: InstanceType<typeof DynamicTopMatter>;
      };
    },

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
    this.refresh();
  },

  created() {
    utils.bus.on('memories:user-config-changed', this.refresh);
  },

  beforeDestroy() {
    utils.bus.off('memories:user-config-changed', this.refresh);
  },

  watch: {
    async $route(to: Route, from: Route) {
      if (to.path === from.path) return;
      await this.refresh();
    },
  },

  methods: {
    async refresh() {
      await this.$nextTick();
      if (!this.noParams || !!this.loading) return;

      try {
        this.items = [];
        this.loading++;

        await this.$nextTick();
        await this.refs.dtm?.refresh?.();

        if (this.routeIsAlbums) {
          this.items = await dav.getAlbums();
        } else if (this.routeIsTags) {
          this.items = await dav.getTags();
        } else if (this.routeIsRecognize) {
          this.items = await dav.getFaceList('recognize');
        } else if (this.routeIsFaceRecognition) {
          this.items = await dav.getFaceList('facerecognition');
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
