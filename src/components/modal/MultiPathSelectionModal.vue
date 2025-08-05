<template>
  <Modal ref="modal" @close="cleanup" v-if="show" size="small">
    <template #title>
      {{ title }}
    </template>

    <ul>
      <li v-for="(path, index) in paths" :key="index" class="path">
        {{ path }}

        <NcActions :inline="1">
          <NcActionButton :aria-label="t('memories', 'Remove')" @click="remove(index)">
            {{ t('memories', 'Remove') }}
            <template #icon> <CloseIcon :size="20" /> </template>
          </NcActionButton>
        </NcActions>
      </li>
    </ul>

    <template #buttons>
      <NcButton @click="add" class="button" type="secondary">
        {{ t('memories', 'Add Path') }}
      </NcButton>
      <NcButton @click="save" class="button" type="primary">
        {{ t('memories', 'Save') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcButton from '@nextcloud/vue/components/NcButton';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as utils from '@services/utils';

import CloseIcon from 'vue-material-design-icons/Close.vue';

export default defineComponent({
  name: 'MultiPathSelectionModal',
  components: {
    Modal,
    NcActions,
    NcActionButton,
    NcButton,
    CloseIcon,
  },

  mixins: [ModalMixin],

  props: {
    title: {
      type: String,
      required: true,
    },
  },

  emits: {
    close: (list: string[]) => true,
  },

  data: () => ({
    paths: [] as string[],
  }),

  methods: {
    open(paths: string[]) {
      this.paths = paths;
      this.show = true;
    },

    cleanup() {
      this.show = false;
      this.$emit('close', this.paths);
    },

    save() {
      this.close();
    },

    async add() {
      this.paths.push(await utils.chooseNcFolder(this.t('memories', 'Add a root to your timeline')));
    },

    remove(index: number) {
      this.paths.splice(index, 1);
    },
  },
});
</script>

<style lang="scss" scoped>
.path {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.1rem;
  padding-left: 10px;
  word-wrap: break-all;
}
</style>
