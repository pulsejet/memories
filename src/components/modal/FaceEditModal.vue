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
      <NcButton @click="save" class="button" type="primary">
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
import { getCurrentUser } from '@nextcloud/auth';
import Modal from './Modal.vue';
import client from '../../services/DavClient';
import * as dav from '../../services/DavRequests';

export default defineComponent({
  name: 'FaceEditModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

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
    $route: async function (from: any, to: any) {
      this.refreshParams();
    },
  },

  methods: {
    close() {
      this.show = false;
      this.$emit('close');
    },

    open() {
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
    },

    refreshParams() {
      this.user = <string>this.$route.params.user || '';
      this.name = <string>this.$route.params.name || '';
      this.oldName = <string>this.$route.params.name || '';
    },

    async save() {
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
          })
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
