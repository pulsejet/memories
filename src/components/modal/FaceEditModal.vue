<template>
  <Modal ref="modal" @close="cleanup" v-if="show">
    <template #title>
      {{ t('memories', 'Rename person') }}
    </template>

    <div class="fields">
      <NcTextField
        class="field"
        :autofocus="true"
        v-model="rawInput"
        :label="t('memories', 'Name')"
        :label-visible="false"
        :placeholder="t('memories', 'Name')"
        @keypress.enter="save()"
      />
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
import { t } from '@services/l10n';
import { routeIs } from '@services/router';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
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
const saving = ref(false);
const abort = useAbort();

const name = computed(() => route.params.name?.toString());
const user = computed(() => route.params.user?.toString());

const input = computed(() => {
  // Prevent leading and trailing spaces in name
  // https://github.com/pulsejet/memories/issues/1074
  return rawInput.value.trim();
});

const canSave = computed(
  () => input.value && name.value !== input.value && isNaN(Number(input.value)) && !saving.value,
);

function open() {
  if (user.value !== utils.uid) {
    showError(t('memories', 'Only user "{user}" can update this person', { user: user.value }));
    return;
  }

  rawInput.value = isNaN(Number(name.value)) ? (name.value ?? String()) : String();
  show.value = true;
}

function cleanup() {
  abort.abort();
  show.value = false;
  saving.value = false;
}

async function save() {
  if (!canSave.value || saving.value) return;
  saving.value = true;
  const signal = abort.renew();

  try {
    if (routeIs.Recognize) {
      await dav.recognizeRenameFace(user.value, name.value, input.value, { signal });
    } else {
      await dav.faceRecognitionRenamePerson(name.value, input.value, { signal });
    }
    signal.throwIfAborted();

    await close();
    await router.replace({
      name: route.name?.toString(),
      params: { user: user.value, name: input.value },
    });
  } catch (error) {
    if (isAbortError(error)) return;
    console.error(error);
    showError(
      t('memories', 'Failed to rename {oldName} to {name}.', {
        oldName: name.value,
        name: input.value,
      }),
    );
  } finally {
    if (!signal.aborted) saving.value = false;
  }
}

defineExpose({ open });
</script>

<style lang="scss" scoped>
.fields {
  margin-top: 8px;
}
</style>
