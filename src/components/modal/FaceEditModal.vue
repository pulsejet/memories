<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Rename person') }}
    </template>

    <div class="fields">
      <!-- Suggestions of already-known person names for the name field -->
      <datalist id="memories-known-person-names">
        <option v-for="known in knownNames" :key="known" :value="known" />
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

<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { showError } from '@services/utils/dialog';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcTextField from '@nextcloud/vue/components/NcTextField';

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { t, n } from '@services/l10n';
import { routeIs } from '@services/router';
import * as utils from '@services/utils/common';
import * as dav from '@services/dav';

defineOptions({
  name: 'FaceEditModal',
});

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const rawInput = ref(String());
const knownNames = ref<string[]>([]);
/** Photos of the person, once known */
const photoCount = ref<number | null>(null);

const name = computed(() => route.params.name?.toString());
const user = computed(() => route.params.user?.toString());

const input = computed(() => {
  // Prevent leading and trailing spaces in name
  // https://github.com/pulsejet/memories/issues/1074
  return rawInput.value.trim();
});

const canSave = computed(() => input.value && name.value !== input.value && isNaN(Number(input.value)));

/** What renaming affects: every face of the person, and not only one photo. */
const scope = computed((): string => {
  // An unnamed group is addressed by its number.
  const isGroup = !isNaN(Number(name.value));
  if (photoCount.value === null) {
    return isGroup
      ? t('memories', 'Naming affects all faces of this group, on all of its photos.')
      : t('memories', 'Renaming affects all faces of this person, on all of their photos.');
  }
  return isGroup
    ? n(
        'memories',
        'Naming affects all faces of this group, on {count} photo.',
        'Naming affects all faces of this group, on {count} photos.',
        photoCount.value,
        { count: photoCount.value },
      )
    : n(
        'memories',
        'Renaming affects all faces of this person, on {count} photo.',
        'Renaming affects all faces of this person, on {count} photos.',
        photoCount.value,
        { count: photoCount.value },
      );
});

function open() {
  if (user.value !== utils.uid) {
    showError(t('memories', 'Only user "{user}" can update this person', { user: user.value }));
    return;
  }

  rawInput.value = isNaN(Number(name.value)) ? (name.value ?? String()) : String();
  photoCount.value = null;
  show.value = true;
  loadKnownNames();
}

function cleanup() {
  show.value = false;
}

/**
 * Load already-known person names from the active backend so the name field
 * can offer them as autocompletion (same comfort as the manual-face modal).
 * Failure is non-fatal: it just means no suggestions are shown.
 */
async function loadKnownNames(): Promise<void> {
  try {
    const app = routeIs.Recognize ? 'recognize' : 'facerecognition';
    const faces = await dav.getFaceList(app);
    const current = faces.find((f) => String(f.name) === String(name.value));
    photoCount.value = current ? Number(current.count) : null;
    const names = faces
      .map((f) => f.name)
      // Keep only real names; unnamed clusters expose a numeric id as their name.
      .filter((known): known is string => !!known && Number.isNaN(Number(known)));
    knownNames.value = Array.from(new Set(names)).sort((a, b) => a.localeCompare(b));
  } catch (e) {
    console.error(e);
    knownNames.value = [];
  }
}

async function save() {
  if (!canSave.value) return;

  try {
    if (routeIs.Recognize) {
      await dav.recognizeRenameFace(user.value, name.value, input.value);
    } else {
      await dav.faceRecognitionRenamePerson(name.value, input.value);
    }

    await close();
    await router.replace({
      name: route.name?.toString(),
      params: { user: user.value, name: input.value },
    });
  } catch (error) {
    console.error(error);
    showError(
      t('memories', 'Failed to rename {oldName} to {name}.', {
        oldName: name.value,
        name: input.value,
      }),
    );
  }
}

defineExpose({ open });
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
