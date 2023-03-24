<template>
  <div v-if="noParams" class="container" :class="{ 'icon-loading': loading }">
    <TopMatter />

    <EmptyContent v-if="items.length === 0 && !loading" />

    <RecycleScroller
      class="grid-recycler hide-scrollbar-mobile"
      :class="{ empty: !items.length }"
      ref="recycler"
      :items="items"
      :skipHover="true"
      :itemSize="itemSize"
      :gridItems="gridItems"
      :updateInterval="100"
      key-field="cluster_id"
      @resize="resize"
    >
      <template v-slot="{ item }">
        <div class="grid-item fill-block" :key="item.cluster_id">
          <Cluster :data="item" @click="click(item)" :link="link" />
        </div>
      </template>
    </RecycleScroller>
  </div>

  <Timeline v-else />
</template>

<script lang="ts">
import { defineComponent } from "vue";

import UserConfig from "../mixins/UserConfig";
import TopMatter from "./top-matter/TopMatter.vue";
import Cluster from "./frame/Cluster.vue";
import Timeline from "./Timeline.vue";
import EmptyContent from "./top-matter/EmptyContent.vue";

import * as dav from "../services/DavRequests";

import { ICluster } from "../types";

export default defineComponent({
  name: "ClusterView",

  components: {
    TopMatter,
    Cluster,
    Timeline,
    EmptyContent,
  },

  mixins: [UserConfig],

  data: () => ({
    items: [] as ICluster[],
    itemSize: 200,
    gridItems: 5,
    loading: 0,
  }),

  props: {
    link: {
      type: Boolean,
      default: true,
    },
  },

  computed: {
    noParams() {
      return !this.$route.params.name && !this.$route.params.user;
    },
  },

  mounted() {
    this.routeChange(this.$route);
    this.resize();
  },

  watch: {
    async $route(to: any, from?: any) {
      this.routeChange(to, from);
    },
  },

  methods: {
    async routeChange(to: any, from?: any) {
      try {
        this.items = [];
        this.loading++;

        if (to.name === "albums") {
          this.items = await dav.getAlbums(3, this.config_albumListSort);
        } else if (to.name === "tags") {
          this.items = await dav.getTags();
        } else if (to.name === "recognize" || to.name === "facerecognition") {
          this.items = await dav.getFaceList(to.name);
        } else if (to.name === "places") {
          this.items = await dav.getPlaces();
        }
      } finally {
        this.loading--;
      }
    },

    click(item: ICluster) {
      this.$emit("click", item);
    },

    resize() {
      const w = (<any>this.$refs.recycler).$el.clientWidth;
      this.gridItems = Math.max(Math.floor(w / 200), 3);
      this.itemSize = Math.floor(w / this.gridItems);
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
.grid-recycler {
  flex: 1;
  max-height: 100%;
  overflow-y: scroll !important;
  &.empty {
    visibility: hidden;
  }
}
.grid-item {
  position: relative;
}
</style>
