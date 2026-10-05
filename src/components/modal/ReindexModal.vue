<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show">
    <template #title>
      {{ t('memories', 'Refresh metadata') }}
    </template>

    <div class="reindex">
      <div>
        {{
          n('memories', 'Processing {n} file', 'Processing {n} files', photos.length, {
            n: photos.length,
          })
        }}
      </div>
      <NcProgressBar :value="Math.round((photosDone * 100) / Math.max(photos.length, 1))" :error="true" />
    </div>
  </Modal>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import { showInfo } from '@nextcloud/dialogs';

const NcProgressBar = defineAsyncComponent(() => import('@nextcloud/vue/components/NcProgressBar'));

import UserConfig from '@mixins/UserConfig';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as dav from '@services/dav';
import * as utils from '@services/utils';

import type { IPhoto } from '@typings';

export default defineComponent({
  name: 'ReindexModal',
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
    console.assert(!_m.modals.reindex, 'ReindexModal created twice');
    _m.modals.reindex = this.open;
  },

  methods: {
    open(photos: IPhoto[]) {
      this.photos = photos;
      this.photosDone = 0;
      if (!photos.length) return;
      this.show = true;
      void this.run();
    },

    cleanup() {
      this.show = false;
      this.photos = [];
    },

    async run() {
      const ok = await dav.reindexPhotos(this.photos, (done) => {
        this.photosDone = done;
      });

      showInfo(this.n('memories', '{n} file refreshed', '{n} files refreshed', ok, { n: ok }));
      this.close();
      utils.bus.emit('memories:timeline:soft-refresh', null);
    },
  },
});
</script>

<style lang="scss" scoped>
.reindex {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
</style>
