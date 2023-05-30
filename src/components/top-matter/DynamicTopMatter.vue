<template>
  <div class="dtm-container" v-if="currentmatter">
    <component ref="child" :is="currentmatter" @load="$emit('load')" />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserMixin from '../../mixins/UserConfig';

import FolderDynamicTopMatter from './FolderDynamicTopMatter.vue';
import OnThisDay from './OnThisDay.vue';

export default defineComponent({
  name: 'DynamicTopMatter',

  mixins: [UserMixin],

  computed: {
    currentmatter(): any {
      if (this.routeIsFolders) {
        return FolderDynamicTopMatter;
      } else if (this.routeIsBase && this.config.enable_top_memories) {
        return OnThisDay;
      }

      return null;
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
