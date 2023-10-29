<template>
  <div class="grid">
    <div class="item fill-block" v-for="item of items" :key="item.fileid">
      <Folder :data="item" />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '@mixins/UserConfig';
import Folder from '@components/frame/Folder.vue';

import type { IFolder } from '@types';

export default defineComponent({
  name: 'ClusterGrid',

  components: {
    Folder,
  },

  mixins: [UserConfig],

  props: {
    items: {
      type: Array<IFolder>,
      required: true,
    },
  },
});
</script>

<style lang="scss" scoped>
.grid {
  display: grid;
  padding: 0 5px;
  grid-gap: 10px;
  grid-template-columns: repeat(auto-fill, minmax(calc(min(50%, 210px) - 5px), 1fr));

  width: calc(100% - 40px); // leave space for scroller
  @media (max-width: 768px) {
    width: calc(100% - 2px); // compensation for negative margin
    grid-gap: 5px;
  }

  > .item {
    aspect-ratio: 1 / 1;
    position: relative;
  }
}
</style>
