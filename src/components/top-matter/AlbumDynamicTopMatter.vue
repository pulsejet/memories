<template>
  <div class="album-dtm">
    <div v-if="album?.location" class="subtitle">
      <MapMarkerOutlineIcon class="icon"></MapMarkerOutlineIcon>
      <span>{{ album.location }}</span>
    </div>

    <div class="avatars" v-if="album && (album?.collaborators.length ?? 0 > 1)">
      <!-- Show own user only if we have other collaborators -->
      <NcAvatar :user="$route.params.user" :showUserStatus="false" />

      <!-- Other collaborators -->
      <template v-for="c of album.collaborators">
        <!-- Links -->
        <NcAvatar v-if="c.type === 3" :isNoUser="true">
          <template #icon>
            <LinkIcon :size="20" />
          </template>
        </NcAvatar>

        <!-- Users and groups -->
        <NcAvatar v-else :key="c.id" :user="c.id" :showUserStatus="false" />
      </template>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as utils from '@services/utils';
import * as dav from '@services/dav';

const NcAvatar = () => import('@nextcloud/vue/dist/Components/NcAvatar.js');

import MapMarkerOutlineIcon from 'vue-material-design-icons/MapMarkerOutline.vue';
import LinkIcon from 'vue-material-design-icons/Link.vue';

export default defineComponent({
  name: 'AlbumDynamicTopMatter',

  components: {
    NcAvatar,
    MapMarkerOutlineIcon,
    LinkIcon,
  },

  data: () => ({
    album: null as dav.IDavAlbum | null,
  }),

  methods: {
    async refresh(): Promise<boolean> {
      // Skip everything if user is not logged in
      if (!utils.uid) return false;

      // Skip if we are not on an album (e.g. on the list)
      const { user, name } = this.$route.params;
      if (!user || !name) return false;

      // Get DAV album for collaborators
      try {
        this.album = await dav.getAlbum(user, name);
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

    :deep .avatardiv {
      margin-right: 2px;
      vertical-align: bottom;
    }
  }
}
</style>
