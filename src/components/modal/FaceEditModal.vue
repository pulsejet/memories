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
        v-model="rawInput"
        :label="t('memories', 'Name')"
        :label-visible="false"
        :placeholder="t('memories', 'Name')"
        list="memories-known-person-names"
        @keypress.enter="save()"
      />

      <p class="scope">{{ scope }}</p>
    </div>

    <template #buttons>
      <NcButton class="button" variant="primary" :disabled="!canSave" @click="save">
        {{ t('memories', 'Update') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

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
    /** Photos of the person, once known */
    photoCount: null as number | null,
  }),

  computed: {
    name() {
      return this.$route.params.name?.toString();
    },

    user() {
      return this.$route.params.user?.toString();
    },

    canSave() {
      return this.input && this.name !== this.input && isNaN(Number(this.input));
    },

    input() {
      // Prevent leading and trailing spaces in name
      // https://github.com/pulsejet/memories/issues/1074
      return this.rawInput.trim();
    },

    /** What renaming affects: every face of the person, and not only one photo. */
    scope(): string {
      // An unnamed group is addressed by its number.
      const isGroup = !isNaN(Number(this.name));
      if (this.photoCount === null) {
        return isGroup
          ? this.t('memories', 'Naming affects all faces of this group, on all of its photos.')
          : this.t('memories', 'Renaming affects all faces of this person, on all of their photos.');
      }
      return isGroup
        ? this.n(
            'memories',
            'Naming affects all faces of this group, on {count} photo.',
            'Naming affects all faces of this group, on {count} photos.',
            this.photoCount,
            { count: this.photoCount },
          )
        : this.n(
            'memories',
            'Renaming affects all faces of this person, on {count} photo.',
            'Renaming affects all faces of this person, on {count} photos.',
            this.photoCount,
            { count: this.photoCount },
          );
    },
  },

  methods: {
    open() {
      if (this.user !== utils.uid) {
        showError(this.t('memories', 'Only user "{user}" can update this person', { user: this.user }));
        return;
      }

      this.rawInput = isNaN(Number(this.name)) ? this.name : String();
      this.photoCount = null;
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
        const current = faces.find((f) => String(f.name) === String(this.name));
        this.photoCount = current ? Number(current.count) : null;
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
          name: this.$route.name?.toString(),
          params: { user: this.user, name: this.input },
        });
      } catch (error) {
        console.error(error);
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

  .scope {
    margin: 8px 0 0;
    font-size: 0.9em;
    padding: 6px 8px;
    border-left: 3px solid var(--color-primary-element);
    background: var(--color-background-hover);
  }
}
</style>
