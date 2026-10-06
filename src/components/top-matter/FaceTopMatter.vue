<template>
  <div class="face-top-matter">
    <NcActions v-if="name">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">
      <div :class="{ 'rename-hover': isReal }" @click="rename">
        {{ displayName }}
      </div>
    </div>

    <div class="right-actions">
      <NcActions :inline="0">
        <!-- root view (not cluster or unassigned) -->
        <template v-if="!name && routeIsRecognize && !routeIsRecognizeUnassigned">
          <NcActionButton :aria-label="t('memories', 'Unassigned faces')" @click="openUnassigned" close-after-click>
            {{ t('memories', 'Unassigned faces') }}
            <template #icon> <UnassignedIcon :size="20" /> </template>
          </NcActionButton>
        </template>

        <!-- real cluster -->
        <template v-if="isReal">
          <NcActionButton :aria-label="t('memories', 'Rename person')" @click="rename" close-after-click>
            {{ t('memories', 'Rename person') }}
            <template #icon> <EditIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionButton
            :aria-label="t('memories', 'Merge with different person')"
            @click="mergeModal?.open()"
            close-after-click
          >
            {{ t('memories', 'Merge with different person') }}
            <template #icon> <MergeIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionCheckbox
            :aria-label="t('memories', 'Mark person in preview')"
            :model-value="config.show_face_rect"
            @change="changeShowFaceRect"
          >
            {{ t('memories', 'Mark person in preview') }}
          </NcActionCheckbox>
          <NcActionButton :aria-label="t('memories', 'Remove person')" @click="deleteModal?.open()" close-after-click>
            {{ t('memories', 'Remove person') }}
            <template #icon> <DeleteIcon :size="20" /> </template>
          </NcActionButton>
        </template>
      </NcActions>
    </div>

    <FaceEditModal ref="editModal" />
    <FaceDeleteModal ref="deleteModal" />
    <FaceMergeModal ref="mergeModal" />
  </div>
</template>

<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import NcActions from '@nextcloud/vue/components/NcActions';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox';

import FaceEditModal from '@components/modal/FaceEditModal.vue';
import FaceDeleteModal from '@components/modal/FaceDeleteModal.vue';
import FaceMergeModal from '@components/modal/FaceMergeModal.vue';

import { useRouteIsRecognize, useRouteIsRecognizeUnassigned } from '@services/route-checker';
import { useUserConfig } from '@services/user-config';
import * as utils from '@services/utils';
import { constants as c } from '@services/utils';
import { t } from '@services/l10n';

import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/Close.vue';
import MergeIcon from 'vue-material-design-icons/Merge.vue';
import UnassignedIcon from 'vue-material-design-icons/AccountQuestion.vue';

defineOptions({
  name: 'FaceTopMatter',
});

const route = useRoute();
const router = useRouter();
const { config, updateSetting } = useUserConfig();
const routeIsRecognize = useRouteIsRecognize();
const routeIsRecognizeUnassigned = useRouteIsRecognizeUnassigned();

const editModal = useTemplateRef<InstanceType<typeof FaceEditModal>>('editModal');
const deleteModal = useTemplateRef<InstanceType<typeof FaceDeleteModal>>('deleteModal');
const mergeModal = useTemplateRef<InstanceType<typeof FaceMergeModal>>('mergeModal');

const name = computed(() => {
  return route.params.name?.toString() || '';
});

const isReal = computed(() => {
  return name.value && name.value !== c.FACE_NULL;
});

const displayName = computed(() => {
  if (routeIsRecognizeUnassigned.value) {
    return t('memories', 'Unassigned faces');
  } else if (!name.value) {
    return t('memories', 'People');
  } else if (utils.isNumber(name.value)) {
    return t('memories', 'Unnamed person');
  }
  return name.value;
});

function back() {
  router.go(-1);
}

function rename() {
  if (isReal.value) editModal.value?.open();
}

function openUnassigned() {
  router.push({
    name: route.name?.toString(),
    params: {
      user: utils.uid as string,
      name: c.FACE_NULL,
    },
  });
}

function changeShowFaceRect() {
  config.show_face_rect = !config.show_face_rect;
  updateSetting('show_face_rect');
  utils.bus.emit('memories:timeline:hard-refresh', null);
}
</script>
