<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Rename person') }}
    </template>

    <div class="fields">
      <!-- Suggestions of already-known person names for the name field -->
      <datalist id="memories-known-person-names">
        <option v-for="n in knownNames" :key="n" :value="n" />
      </datalist>

      <NcTextField
        class="field"
        :autofocus="true"
        :value.sync="rawInput"
        :label="t('memories', 'Name')"
        :label-visible="false"
        :placeholder="t('memories', 'Name')"
        list="memories-known-person-names"
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

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import * as utils from '@services/utils';
import * as dav from '@services/dav';

export default defineComponent({
  name: 'FaceEditModal',
  components: {
    NcButton,
    NcTextField,
    Modal,
  },

  mixins: [ModalMixin],

  emits: [],

  data: () => ({
    rawInput: String(),
    knownNames: [] as string[],
  }),

  computed: {
    name() {
      return this.$route.params.name;
    },

    user() {
      return this.$route.params.user;
    },

    canSave() {
      return this.input && this.name !== this.input && isNaN(Number(this.input));
    },

    input() {
      // Prevent leading and trailing spaces in name
      // https://github.com/pulsejet/memories/issues/1074
      return this.rawInput.trim();
    },
  },

  methods: {
    open() {
      if (this.user !== utils.uid) {
        showError(this.t('memories', 'Only user "{user}" can update this person', { user: this.user }));
        return;
      }

      this.rawInput = isNaN(Number(this.name)) ? this.name : String();
      this.show = true;
      this.loadKnownNames();
    },

    cleanup() {
      this.show = false;
    },

    /**
     * Load already-known person names from the active backend so the name field
     * can offer them as autocompletion (same comfort as the manual-face modal).
     * Failure is non-fatal: it just means no suggestions are shown.
     */
    async loadKnownNames(): Promise<void> {
      try {
        const app = this.routeIsRecognize ? 'recognize' : 'facerecognition';
        const faces = await dav.getFaceList(app);
        const names = faces
          .map((f) => f.name)
          // Keep only real names; unnamed clusters expose a numeric id as their name.
          .filter((n): n is string => !!n && Number.isNaN(Number(n)));
        this.knownNames = Array.from(new Set(names)).sort((a, b) => a.localeCompare(b));
      } catch (e) {
        console.error(e);
        this.knownNames = [];
      }
    },

    async save() {
      if (!this.canSave) return;

      try {
        if (this.routeIsRecognize) {
          await dav.recognizeRenameFace(this.user, this.name, this.input);
        } else {
          await dav.faceRecognitionRenamePerson(this.name, this.input);
        }

        await this.close();
        await this.$router.replace({
          name: this.$route.name as string,
          params: { user: this.user, name: this.input },
        });
      } catch (error) {
        console.log(error);
        showError(
          this.t('memories', 'Failed to rename {oldName} to {name}.', {
            oldName: this.name,
            name: this.input,
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
