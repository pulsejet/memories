<template>
  <div class="dtm-container" v-if="currentmatter || viewName">
    <div v-if="viewName" class="header">{{ viewName }}</div>
    <component ref="child" v-if="currentmatter" :is="currentmatter" @load="$emit('load')" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import { Component } from 'vue';

import UserMixin from '../../mixins/UserConfig';

import FolderDynamicTopMatter from './FolderDynamicTopMatter.vue';
import PlacesDynamicTopMatterVue from './PlacesDynamicTopMatter.vue';
import OnThisDay from './OnThisDay.vue';

import * as PublicShareHeader from './PublicShareHeader';
import * as strings from '../../services/strings';

export default defineComponent({
  name: 'DynamicTopMatter',

  mixins: [UserMixin],

  emits: {
    load: () => true,
  },

  computed: {
    currentmatter(): Component | null {
      if (this.routeIsFolders) {
        return FolderDynamicTopMatter;
      } else if (this.routeIsPlaces) {
        return PlacesDynamicTopMatterVue;
      } else if (this.routeIsBase && this.config.enable_top_memories) {
        return OnThisDay;
      }

      return null;
    },

    /** Get view name for dynamic top matter */
    viewName(): string {
      // Show album name for album view
      if (this.routeIsAlbums) {
        return this.$route.params.name || '';
      }

      // Show share name for public shares
      if (this.routeIsPublic) {
        return PublicShareHeader.title;
      }

      // Only static top matter for these routes
      if (this.routeIsTags || this.routeIsPeople || this.routeIsPlaces) {
        return '';
      }

      return strings.viewName(this.$route.name);
    },
  },

  methods: {
    async refresh(): Promise<boolean> {
      if (this.currentmatter) {
        await this.$nextTick();
        // @ts-ignore
        return (await this.$refs.child?.refresh?.()) ?? false;
      }

      return false;
    },
  },
});
</script>

<style lang="scss" scoped>
.dtm-container {
  > .header {
    font-size: 2.5em;
    position: relative;
    display: block;
    line-height: 1.2em;

    // more padding on right for scroller thumb
    padding: 25px 60px 10px 10px;

    @media (max-width: 768px) {
      font-size: 1.8em;
      padding: 15px 30px 7px 12px;
      html.native & {
        // header is empty, more top padding
        padding: 25px 30px 7px 18px;
      }
    }
  }
}
</style>
