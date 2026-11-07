<template>
  <NcEmptyContent class="not-found" :name="t('memories', 'Page not found')">
    <template #icon>
      <FileQuestionIcon />
    </template>
    <template v-if="uid" #action>
      <NcButton :to="{ name: routes.Base.name }">{{ t('memories', 'Go to timeline') }}</NcButton>
    </template>
  </NcEmptyContent>
</template>

<script setup lang="ts">
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent';
import NcButton from '@nextcloud/vue/components/NcButton';

import { routes } from '@services/router';
import { uid } from '@services/utils/auth';
import { t } from '@services/l10n';

import FileQuestionIcon from 'vue-material-design-icons/FileQuestion.vue';
</script>

<style lang="scss" scoped>
.not-found {
  height: 100%;
}
</style>
