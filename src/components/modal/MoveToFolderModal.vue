<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
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

import { showInfo } from '@nextcloud/dialogs';

const NcProgressBar = () => import('@nextcloud/vue/dist/Components/NcProgressBar.js');

import UserConfig from '@mixins/UserConfig';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as dav from '@services/dav';
import * as utils from '@services/utils';

import path from 'path';

import type { IPhoto } from '@typings';

export default defineComponent({
  name: 'MoveToFolderModal',
  components: {
    NcProgressBar,
    Modal,
  },

  mixins: [UserConfig, ModalMixin],

  data: () => ({
    photos: [] as IPhoto[],
    photosDone: 0,
  }),

  created() {
    console.assert(!_m.modals.moveToFolder, 'MoveToFolderModal created twice');
    _m.modals.moveToFolder = this.open;
  },

  methods: {
    open(photos: IPhoto[]) {
      this.photosDone = 0;
      this.show = false;
      this.photos = photos;
      this.chooseFolderPath();
    },

    cleanup() {
      this.show = false;
      this.photos = [];
    },

    async chooseFolderPath() {
      let mode = 'move' as 'move' | 'organise' | 'copy';
      let destination = await utils.chooseNcFolder(
        this.t('memories', 'Choose a folder'),
        this.config.folders_path,
        () => [
          {
            label: 'Move and organise',
            callback: () => (mode = 'organise'),
          },
          {
            label: 'Copy',
            callback: () => (mode = 'copy'),
          },
          {
            label: 'Move',
            type: 'primary',
            callback: () => (mode = 'move'),
          },
        ],
      );
      console.log(mode);

      let gen;
      switch (mode) {
        case 'organise' : {
          gen = dav.movePhotosByDate(this.photos, destination, false);
          break;
        }
        case 'copy' : {
          gen = dav.copyPhotos(this.photos, destination, false);
          break;
        }
        case 'move' : {
          gen = dav.movePhotos(this.photos, destination, false);
          break;
        }
      }

      this.show = true;

      for await (const fids of gen) {
        this.photosDone += fids.filter(Boolean).length;
        utils.bus.emit('memories:timeline:soft-refresh', null);
      }

      const n = this.photosDone;
      if (mode === 'copy') {
        showInfo(this.n('memories', '{n} item copied to folder', '{n} items copied to folder', n, { n }));
      } else {
        showInfo(this.n('memories', '{n} item copied to folder', '{n} items copied to folder', n, { n }));
      }
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
