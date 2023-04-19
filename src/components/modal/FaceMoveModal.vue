<template>
  <Modal @close="close" size="large" v-if="show">
    <template #title>
      {{ t('memories', 'Move selected photos to person') }}
    </template>

    <div class="outer">
      <FaceList @select="clickFace" />
    </div>

    <template #buttons>
      <NcButton @click="close" class="button" type="error">
        {{ t('memories', 'Cancel') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField');

import { showError } from '@nextcloud/dialogs';
import { getCurrentUser } from '@nextcloud/auth';
import { IPhoto, IFace } from '../../types';
import Cluster from '../frame/Cluster.vue';
import FaceList from './FaceList.vue';

import Modal from './Modal.vue';
import client from '../../services/DavClient';
import * as dav from '../../services/DavRequests';

export default defineComponent({
  name: 'FaceMoveModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
    Cluster,
    FaceList,
  },

  props: {
    updateLoading: {
      type: Function,
      required: true,
    },
  },

  data: () => ({
    show: false,
    photos: [] as IPhoto[],
  }),

  methods: {
    open(photos: IPhoto[]) {
      if (this.photos.length) {
        // is processing
        return;
      }

      // check ownership
      const user = this.$route.params.user || '';
      if (this.$route.params.user !== getCurrentUser()?.uid) {
        showError(
          this.t('memories', 'Only user "{user}" can update this person', {
            user,
          })
        );
        return;
      }

      this.show = true;
      this.photos = photos;
    },

    close() {
      this.photos = [];
      this.show = false;
      this.$emit('close');
    },

    moved(list: IPhoto[]) {
      this.$emit('moved', list);
    },

    async clickFace(face: IFace) {
      const user = this.$route.params.user || '';
      const name = this.$route.params.name || '';

      const newName = String(face.name || face.cluster_id);

      if (
        !confirm(
          this.t('memories', 'Are you sure you want to move the selected photos from {name} to {newName}?', {
            name,
            newName,
          })
        )
      ) {
        return;
      }

      try {
        this.show = false;
        this.updateLoading(1);

        // Create map to return IPhoto later
        let photoMap = new Map<number, IPhoto>();
        for (const photo of this.photos) {
          photoMap.set(photo.fileid, photo);
        }

        // Create move calls
        const calls = this.photos.map((p) => async () => {
          try {
            await client.moveFile(
              `/recognize/${user}/faces/${name}/${p.fileid}-${p.basename}`,
              `/recognize/${face.user_id}/faces/${newName}/${p.fileid}-${p.basename}`
            );
            return photoMap.get(p.fileid);
          } catch (e) {
            console.error(e);
            showError(this.t('memories', 'Error while moving {basename}', <any>p));
          }
        });
        for await (const resp of dav.runInParallel(calls, 10)) {
          const valid = resp.filter((r): r is IPhoto => r !== undefined);
          this.moved(valid);
        }
      } catch (error) {
        console.error(error);
        showError(this.t('photos', 'Failed to move {name}.', { name }));
      } finally {
        this.updateLoading(-1);
        this.close();
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}
</style>
