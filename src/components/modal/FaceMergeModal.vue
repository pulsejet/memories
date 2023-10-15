<template>
  <Modal @close="close" size="large" v-if="show">
    <template #title>
      {{ t('memories', 'Merge {name} with person', { name: $route.params.name }) }}
    </template>

    <div class="outer">
      <FaceList @select="clickFace" />

      <div v-if="processingTotal > 0">
        <NcProgressBar :value="Math.round((processing * 100) / processingTotal)" :error="true" />
      </div>
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
const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar');

import { showError } from '@nextcloud/dialogs';
import { IFileInfo, IFace } from '../../types';
import Cluster from '../frame/Cluster.vue';
import FaceList from './FaceList.vue';

import Modal from './Modal.vue';
import client from '../../services/dav/client';
import * as dav from '../../services/dav';
import * as utils from '../../services/utils';

export default defineComponent({
  name: 'FaceMergeModal',
  components: {
    NcButton,
    NcTextField,
    NcProgressBar,
    Modal,
    Cluster,
    FaceList,
  },

  emits: [],

  data: () => ({
    processing: 0,
    processingTotal: 0,
    show: false,
  }),

  methods: {
    close() {
      this.show = false;
    },

    open() {
      const user = this.$route.params.user || '';
      if (this.$route.params.user !== utils.uid) {
        showError(
          this.t('memories', 'Only user "{user}" can update this person', {
            user,
          }),
        );
        return;
      }
      this.show = true;
    },

    async clickFace(face: IFace) {
      const user = this.$route.params.user || '';
      const name = this.$route.params.name || '';

      const newName = String(face.name || face.cluster_id);

      if (
        !(await utils.confirmDestructive({
          title: this.t('memories', 'Merge faces'),
          message: this.t('memories', 'Merge {name} with {newName}?', {
            name: utils.isNumber(name) ? this.t('memories', 'unnamed person') : name,
            newName: utils.isNumber(newName) ? this.t('memories', 'unnamed person') : newName,
          }),
          confirm: this.t('memories', 'Continue'),
          confirmClasses: 'error',
          cancel: this.t('memories', 'Cancel'),
        }))
      ) {
        return;
      }

      try {
        // Get all files for current face
        let res = (await client.getDirectoryContents(`/recognize/${user}/faces/${name}`, { details: true })) as any;
        let data: IFileInfo[] = res.data;
        this.processingTotal = data.length;

        // Don't try too much
        let failures = 0;

        // Create move calls
        const calls = data.map((p) => async () => {
          // Short circuit if we have too many failures
          if (failures === 10) {
            showError(this.t('memories', 'Too many failures, aborting'));
            failures++;
          }
          if (failures >= 10) return;

          // Move to new face with webdav
          try {
            await client.moveFile(
              `/recognize/${user}/faces/${name}/${p.basename}`,
              `/recognize/${face.user_id}/faces/${newName}/${p.basename}`,
            );
          } catch (e) {
            console.error(e);
            showError(this.t('memories', 'Error while moving {basename}', p));
            failures++;
          } finally {
            this.processing++;
          }
        });
        for await (const _ of dav.runInParallel(calls, 10)) {
          // nothing to do
        }

        // Go to new face
        if (failures === 0) {
          this.$router.push({
            name: 'recognize',
            params: { user: face.user_id, name: newName },
          });
          this.close();
        }
      } catch (error) {
        console.error(error);
        showError(this.t('photos', 'Failed to move {name}.', { name }));
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
