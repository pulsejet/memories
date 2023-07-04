<template>
  <div class="face-top-matter">
    <NcActions v-if="name">
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name" :class="{ rename: isReal }" @click="rename">{{ displayName }}</div>

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
            @click="$refs.mergeModal?.open()"
            close-after-click
          >
            {{ t('memories', 'Merge with different person') }}
            <template #icon> <MergeIcon :size="20" /> </template>
          </NcActionButton>
          <NcActionCheckbox
            :aria-label="t('memories', 'Mark person in preview')"
            :checked.sync="config.show_face_rect"
            @change="changeShowFaceRect"
          >
            {{ t('memories', 'Mark person in preview') }}
          </NcActionCheckbox>
          <NcActionButton
            :aria-label="t('memories', 'Remove person')"
            @click="$refs.deleteModal?.open()"
            close-after-click
          >
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

<script lang="ts">
import { defineComponent } from 'vue';

import UserConfig from '../../mixins/UserConfig';
import NcActions from '@nextcloud/vue/dist/Components/NcActions';
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton';
import NcActionCheckbox from '@nextcloud/vue/dist/Components/NcActionCheckbox';

import { emit } from '@nextcloud/event-bus';
import { getCurrentUser } from '@nextcloud/auth';

import FaceEditModal from '../modal/FaceEditModal.vue';
import FaceDeleteModal from '../modal/FaceDeleteModal.vue';
import FaceMergeModal from '../modal/FaceMergeModal.vue';
import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/Close.vue';
import MergeIcon from 'vue-material-design-icons/Merge.vue';
import UnassignedIcon from 'vue-material-design-icons/AccountQuestion.vue';

import * as utils from '../../services/Utils';

export default defineComponent({
  name: 'FaceTopMatter',
  components: {
    NcActions,
    NcActionButton,
    NcActionCheckbox,
    FaceEditModal,
    FaceDeleteModal,
    FaceMergeModal,
    BackIcon,
    EditIcon,
    DeleteIcon,
    MergeIcon,
    UnassignedIcon,
  },

  mixins: [UserConfig],

  computed: {
    name() {
      return this.$route.params.name || '';
    },

    isReal() {
      return this.name && this.name !== utils.constants.FACE_NULL;
    },

    displayName() {
      if (this.routeIsRecognizeUnassigned) {
        return this.t('memories', 'Unassigned faces');
      } else if (!this.name) {
        return this.t('memories', 'People');
      }
      return this.name;
    },
  },

  methods: {
    back() {
      this.$router.go(-1);
    },

    rename() {
      if (this.name) (<any>this.$refs.editModal)?.open();
    },

    openUnassigned() {
      this.$router.push({
        name: this.$route.name as string,
        params: {
          user: String(getCurrentUser()?.uid),
          name: utils.constants.FACE_NULL,
        },
      });
    },

    changeShowFaceRect() {
      this.updateSetting('show_face_rect');
      emit('memories:timeline:hard-refresh', {});
    },
  },
});
</script>

<style scoped lang="scss">
.face-top-matter {
  .name.rename:hover {
    cursor: text;
    text-decoration: underline;
    text-decoration-color: var(--color-placeholder-light);
    text-underline-offset: 5px;
  }
}
</style>
