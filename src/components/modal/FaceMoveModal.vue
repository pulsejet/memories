<template>
  <Modal ref="modal" @close="cleanup" size="large" v-if="show">
    <template #title>
      {{ t('memories', 'Move selected photos to person') }}
    </template>

    <div class="outer">
      <FaceList :plus="true" @select="clickFace" />
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

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');

import Cluster from '@components/frame/Cluster.vue';
import FaceList from './FaceList.vue';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { IPhoto, IFace } from '@typings';

export default defineComponent({
  name: 'FaceMoveModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
    Cluster,
    FaceList,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    photos: [] as IPhoto[],
  }),

  created() {
    console.assert(!_m.modals.moveToFace, 'FaceMoveModal created twice');
    _m.modals.moveToFace = this.open;
  },

  methods: {
    async open(photos: IPhoto[]) {
      if (this.photos.length) {
        // is processing
        return;
      }

      // check ownership
      const user = this.$route.params.user || '';
      if (!(await utils.canManagePersonCluster(user))) {
        showError(
          this.t('memories', 'Only user "{user}" can update this person', {
            user,
          }),
        );
        return;
      }

      this.show = true;
      this.photos = photos;
    },

    cleanup() {
      this.show = false;
      this.photos = [];
    },

    moved(photos: IPhoto[]) {
      utils.bus.emit('memories:timeline:deleted', photos);
    },

    async clickFace(face: IFace) {
      const user = this.$route.params.user || '';
      const name = this.$route.params.name || '';
      const target = String(face.name || face.cluster_id);

      if (
        !(await utils.confirmDestructive({
          title: this.t('memories', 'Move to person'),
          message: this.t('memories', 'Move the selected photos to {target}?', {
            target: utils.isNumber(target) ? this.t('memories', 'unnamed person') : target,
          }),
          confirm: this.t('memories', 'Move'),
          confirmClasses: 'primary',
          cancel: this.t('memories', 'Cancel'),
        }))
      ) {
        return;
      }

      try {
        // Create map to return IPhoto later
        const map = new Map<number, IPhoto>();
        for (const photo of this.photos.filter((p) => p.faceid)) {
          map.set(photo.faceid!, photo);
        }

        // Run WebDAV query
        const photos = Array.from(map.values());
        for await (let delIds of dav.recognizeMoveFaceImages(user, name, target, photos)) {
          this.moved(
            delIds
              .filter(utils.truthy)
              .map((id) => map.get(id))
              .filter(utils.truthy),
          );
        }
      } catch (error) {
        console.error(error);
        showError(this.t('memories', 'An error occurred while moving photos from {name}.', { name }));
      } finally {
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
