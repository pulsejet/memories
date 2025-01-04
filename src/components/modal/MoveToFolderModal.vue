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
      enum Mode {
        Move = 1,
        Copy = 2,
        Organise = 3,
      }
      let mode: Mode = Mode.Move as Mode;
      let destination = await utils.chooseNcFolder(
        this.t('memories', 'Choose a folder'),
        this.config.folders_path,
        () => [
          {
            label: 'Move and organise',
            callback: () => (mode = Mode.Organise),
          },
          {
            label: 'Copy',
            callback: () => (mode = Mode.Copy),
          },
          {
            label: 'Move',
            type: 'primary',
            callback: () => (mode = Mode.Move),
          },
        ],
      );

      let gen;
      // Fails if the target exists, same behavior with Nextcloud files implementation.
      switch (mode) {
        case Mode.Organise: {
          gen = dav.movePhotosByDate(this.photos, destination, false);
          break;
        }
        case Mode.Copy: {
          gen = dav.copyPhotos(this.photos, destination, false);
          break;
        }
        case Mode.Move: {
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
      if (mode === Mode.Copy) {
        showInfo(this.n('memories', '{n} item copied to folder', '{n} items copied to folder', n, { n }));
      } else {
        showInfo(this.n('memories', '{n} item moved to folder', '{n} items moved to folder', n, { n }));
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
