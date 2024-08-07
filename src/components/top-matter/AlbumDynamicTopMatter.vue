<template>
  <div class="album-dtm">
    <div v-if="viewsubTitle" class="subtitle">
      <MapMarkerOutlineIcon class="icon"></MapMarkerOutlineIcon>
      <span>{{ viewsubTitle }}</span>
    </div>

    <div class="avatars">
      <NcAvatar
        v-for="c of collaborators"
        :key="c"
        :user="c"
        :displayName="c"
        :showUserStatus="false"
        :disableMenu="false"
      />
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as dav from '@services/dav';

import MapMarkerOutlineIcon from 'vue-material-design-icons/MapMarkerOutline.vue';
const NcAvatar = () => import('@nextcloud/vue/dist/Components/NcAvatar.js');

type Collaborator = {
  id: string;
  label: string;
};

export default defineComponent({
  name: 'AlbumDynamicTopMatter',

  components: {
    MapMarkerOutlineIcon,
    NcAvatar,
  },

  data: () => ({
    album: null as any,
  }),

  computed: {

    collaborators(): string[] {
      if (this.album) {
        return [this.$route.params.user, ...this.album.collaborators.map((c: Collaborator) => c.id)];
      }
      return [];
    },

    /** Get view subtitle for dynamic top matter */
    viewsubTitle(): string {
      if (this.album) {
        return this.album.location ?? String();
      }
      return String();
    },
  },

  methods: {
    async refresh(): Promise<boolean> {
      try {
        this.album = await dav.getAlbum(this.$route.params.user, this.$route.params.name);
        return true;
      } catch (e) {
        return false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.album-dtm {
  > .subtitle {
    font-size: 1.1em;
    line-height: 1.2em;
    margin-top: 0.5em;
    color: var(--color-text-lighter);
    display: flex;
    padding-left: 10px;
  }

  .icon {
    margin-right: 5px;
  }

  > .avatars {
    line-height: 1.2em;
    margin-top: 0.5em;
    padding-left: 10px;
  }
}
</style>
