<template>
  <div v-if="name" class="face-top-matter">
    <NcActions>
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t('memories', 'Back') }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>

    <div class="name">{{ name }}</div>

    <div class="right-actions">
      <NcActions :inline="1">
        <NcActionButton :aria-label="t('memories', 'Rename person')" @click="$refs.editModal?.open()" close-after-click>
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

import FaceEditModal from '../modal/FaceEditModal.vue';
import FaceDeleteModal from '../modal/FaceDeleteModal.vue';
import FaceMergeModal from '../modal/FaceMergeModal.vue';
import BackIcon from 'vue-material-design-icons/ArrowLeft.vue';
import EditIcon from 'vue-material-design-icons/Pencil.vue';
import DeleteIcon from 'vue-material-design-icons/Close.vue';
import MergeIcon from 'vue-material-design-icons/Merge.vue';

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
  },

  mixins: [UserConfig],

  data: () => ({
    name: '',
  }),

  watch: {
    $route: function (from: any, to: any) {
      this.createMatter();
    },
  },

  mounted() {
    this.createMatter();
  },

  methods: {
    createMatter() {
      this.name = <string>this.$route.params.name || '';
    },

    back() {
      this.$router.go(-1);
    },

    changeShowFaceRect() {
      this.updateSetting('show_face_rect');
      emit('memories:timeline:hard-refresh', {});
    },
  },
});
</script>
