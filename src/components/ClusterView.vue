<template>
  <div v-if="noParams" class="container" :class="{ 'icon-loading': loading }">
    <TopMatter />

    <EmptyContent v-if="!items.length && !loading" />

    <ClusterGrid :items="items" />
  </div>

  <Timeline v-else />
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { subscribe, unsubscribe } from '@nextcloud/event-bus';

import UserConfig from '../mixins/UserConfig';
import TopMatter from './top-matter/TopMatter.vue';
import ClusterGrid from './ClusterGrid.vue';
import Timeline from './Timeline.vue';
import EmptyContent from './top-matter/EmptyContent.vue';

import * as dav from '../services/DavRequests';

import type { ICluster } from '../types';

export default defineComponent({
  name: 'ClusterView',

  components: {
    TopMatter,
    ClusterGrid,
    Timeline,
    EmptyContent,
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
  },

  mounted() {
    this.routeChange();
  },

  created() {
    subscribe(this.configEventName, this.routeChange);
  },

  beforeDestroy() {
    unsubscribe(this.configEventName, this.routeChange);
  },

  watch: {
    async $route() {
      this.routeChange();
    },
  },

  methods: {
    async routeChange() {
      try {
        const route = this.$route.name;
        this.items = [];
        this.loading++;

        if (route === 'albums') {
          this.items = await dav.getAlbums(3, this.config.album_list_sort);
        } else if (route === 'tags') {
          this.items = await dav.getTags();
        } else if (route === 'recognize' || route === 'facerecognition') {
          this.items = await dav.getFaceList(route);
        } else if (route === 'places') {
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
}
</style>
