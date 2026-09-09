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

<script lang="ts">
import { defineComponent } from 'vue';

import NcDialog from '@nextcloud/vue/components/NcDialog';
import NcTextField from '@nextcloud/vue/components/NcTextField';

import { translate as t } from '@services/l10n';

export default defineComponent({
  name: 'PromptDialog',

  components: {
    NcDialog,
    NcTextField,
  },

  props: {
    title: {
      type: String,
      default: '',
    },

    message: {
      type: String,
      default: '',
    },

    label: {
      type: String,
      default: '',
    },

    password: {
      type: Boolean,
      default: false,
    },
  },

  emits: {
    close: (_value: string | null) => true,
  },

  data: () => ({
    value: '',
  }),

  computed: {
    buttons() {
      return [
        {
          label: t('memories', 'Cancel'),
          callback: () => this.close(null),
        },
        {
          label: t('memories', 'OK'),
          variant: 'primary' as const,
          callback: () => this.submit(),
        },
      ];
    },
  },

  mounted() {
    (this.$refs.input as unknown as { focus?: () => void })?.focus?.();
  },

  methods: {
    submit() {
      this.close(this.value);
    },

    close(value: string | null) {
      this.$emit('close', value);
    },
  },
});
</script>
