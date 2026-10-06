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
import { computed, ref, useTemplateRef, defineAsyncComponent } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { showError } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import Modal from './Modal.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import { useRouteIsRecognize } from '@services/route-checker';
import * as utils from '@services/utils';
import * as dav from '@services/dav';

defineOptions({
  name: 'FaceEditModal',
});

const route = useRoute();
const router = useRouter();
const modal = useTemplateRef('modal');
const { show, close } = useModal(modal);
const routeIsRecognize = useRouteIsRecognize();

const rawInput = ref(String());

const name = computed(() => route.params.name?.toString());
const user = computed(() => route.params.user?.toString());

const input = computed(() => {
  // Prevent leading and trailing spaces in name
  // https://github.com/pulsejet/memories/issues/1074
  return rawInput.value.trim();
});

const canSave = computed(() => input.value && name.value !== input.value && isNaN(Number(input.value)));

function open() {
  if (user.value !== utils.uid) {
    showError(t('memories', 'Only user "{user}" can update this person', { user: user.value }));
    return;
  }

  rawInput.value = isNaN(Number(name.value)) ? (name.value ?? String()) : String();
  show.value = true;
}

function cleanup() {
  show.value = false;
}

async function save() {
  if (!canSave.value) return;

  try {
    if (routeIsRecognize.value) {
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
}
</style>
