<template>
  <Modal @close="close" v-if="show">
    <template #title>
      {{ t('memories', 'Rename person') }}
    </template>

    <div class="fields">
      <NcTextField
        class="field"
        :autofocus="true"
        :value.sync="name"
        :label="t('memories', 'Name')"
        :label-visible="false"
        :placeholder="t('memories', 'Name')"
        @keypress.enter="save()"
      />
    </div>

    <template #buttons>
      <NcButton class="button" type="primary" :disabled="!canSave" @click="save">
        {{ t('memories', 'Update') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField');

import { showError } from '@nextcloud/dialogs';

import Modal from './Modal.vue';

import * as utils from '../../services/utils';
import * as dav from '../../services/dav';

export default defineComponent({
  name: 'FaceEditModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  emits: [],

  data: () => ({
    show: false,
    user: '',
    name: '',
    oldName: '',
  }),

  mounted() {
    this.refreshParams();
  },

  watch: {
    $route() {
      this.refreshParams();
    },
  },

  computed: {
    canSave() {
      return this.name !== this.oldName && this.name !== '' && isNaN(Number(this.name));
    },
  },

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

    refreshParams() {
      this.user = String(this.$route.params.user);
      this.name = String(this.$route.params.name);
      this.oldName = this.name;

      // if name is number then it is blank
      if (!isNaN(Number(this.name))) {
        this.name = String();
      }
    },

    async save() {
      if (!this.canSave) return;

      try {
        if (this.$route.name === 'recognize') {
          await dav.recognizeRenameFace(this.user, this.oldName, this.name);
        } else {
          await dav.faceRecognitionRenamePerson(this.oldName, this.name);
        }
        this.$router.replace({
          name: this.$route.name as string,
          params: { user: this.user, name: this.name },
        });
        this.close();
      } catch (error) {
        console.log(error);
        showError(
          this.t('photos', 'Failed to rename {oldName} to {name}.', {
            oldName: this.oldName,
            name: this.name,
          }),
        );
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.fields {
  margin-top: 8px;
}
</style>
