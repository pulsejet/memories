<template>
  <Modal @close="close" size="normal" v-if="processing">
    <template #title>
      {{ t('memories', 'Move to folder') }}
    </template>

    <div class="outer">
      <NcProgressBar :value="Math.round((photosDone * 100) / photos.length)" :error="true" />
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as dav from '../../services/DavRequests';
import { getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs';
import { showInfo } from '@nextcloud/dialogs';
import { IPhoto } from '../../types';

const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar');

import UserConfig from '../../mixins/UserConfig';
import Modal from './Modal.vue';

export default defineComponent({
  name: 'MoveToFolderModal',
  components: {
    NcProgressBar,
    Modal,
  },

  mixins: [UserConfig],

  data: () => ({
    photos: [] as IPhoto[],
    photosDone: 0,
    processing: false,
  }),

  methods: {
    open(photos: IPhoto[]) {
      this.photosDone = 0;
      this.processing = false;
      this.photos = photos;

      this.chooseFolderPath();
    },

    moved(photos: IPhoto[]) {
      this.$emit('moved', photos);
    },

    close() {
      this.photos = [];
      this.processing = false;
      this.$emit('close');
    },

    async chooseFolderModal(title: string, initial: string) {
      const picker = getFilePickerBuilder(title)
        .setMultiSelect(false)
        .setModal(false)
        .setType(FilePickerType.Move)
        .addMimeTypeFilter('httpd/unix-directory')
        .allowDirectories()
        .startAt(initial)
        .build();

      return await picker.pick();
    },

    async chooseFolderPath() {
      let destination = await this.chooseFolderModal(this.t('memories', 'Choose a folder'), this.config.folders_path);
      // Fails if the target exists, same behavior with Nextcloud files implementation.
      const gen = dav.movePhotos(this.photos, destination, false);
      this.processing = true;

      for await (const fids of gen) {
        this.photosDone += fids.filter((f) => f).length;
        this.moved(this.photos.filter((p) => fids.includes(p.fileid)));
      }

      const n = this.photosDone;
      showInfo(this.n('memories', '{n} item moved to folder', '{n} items moved to folder', n, { n }));
      this.close();
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  margin-top: 15px;
}
</style>
