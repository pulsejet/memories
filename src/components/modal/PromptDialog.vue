<template>
  <NcDialog :name="title" :message="message" :buttons="buttons" @closing="close(null)">
    <NcTextField
      ref="input"
      v-model="value"
      :label="label"
      :type="password ? 'password' : 'text'"
      @keydown.enter="submit"
    />
  </NcDialog>
</template>

<script setup lang="ts">
import { computed, ref, useTemplateRef, onMounted } from 'vue';

import NcDialog from '@nextcloud/vue/components/NcDialog';
import NcTextField from '@nextcloud/vue/components/NcTextField';

import { translate as t } from '@services/l10n';

defineOptions({
  name: 'PromptDialog',
});

const props = withDefaults(
  defineProps<{
    title?: string;
    message?: string;
    label?: string;
    password?: boolean;
  }>(),
  {
    title: '',
    message: '',
    label: '',
    password: false,
  },
);

const emit = defineEmits<{
  (e: 'close', value: string | null): void;
}>();

const input = useTemplateRef<{ focus?: () => void }>('input');

const value = ref('');

const buttons = computed(() => [
  {
    label: t('memories', 'Cancel'),
    callback: () => close(null),
  },
  {
    label: t('memories', 'OK'),
    variant: 'primary' as const,
    callback: () => submit(),
  },
]);

onMounted(() => {
  input.value?.focus?.();
});

function submit() {
  close(value.value);
}

function close(closeValue: string | null) {
  emit('close', closeValue);
}
</script>
