<template>
  <div class="embedded-tag-selector">
    <NcSelect
      :no-wrap="noWrap"
      v-model="selectedTags"
      :options="allTags"
      :multiple="multiple"
      :loading="loading"
      :input-label="inputLabel"
      :placeholder="placeholder"
      :keep-open=true
      :disabled="disabled"
    />
  </div>
</template>

<script>
import { defineComponent } from 'vue';
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js';
import axios from '@nextcloud/axios';
import { API } from '@services/API';

export default defineComponent({
  name: 'EmbeddedTagSelector',

  components: {
    NcSelect,
  },

  props: {
    /** Selected tag values */
    value: {
      type: Array,
      default: () => [],
    },

    /** Whether to allow multiple selections */
    multiple: {
      type: Boolean,
      default: true,
    },

    /** Input label */
    inputLabel: {
      type: String,
      default: 'Select Tags',
    },

    /** Placeholder text */
    placeholder: {
      type: String,
      default: 'Search for tags...',
    },

    /** Whether to wrap selected items */
    noWrap: {
      type: Boolean,
      default: false,
    },

    /** Disabled state */
    disabled: {
      type: Boolean,
      default: false,
    },

    /** Whether to show full path instead of just tag name */
    showFullPath: {
      type: Boolean,
      default: false,
    },
  },

  emits: ['update:value'],

  data() {
    return {
      allTags: [],
      selectedTags: [],
      loading: false,
    };
  },

  watch: {

    selectedTags(newSelection) {
      this.$emit('update:value', newSelection);
    },
  },

  async mounted() {
    await this.loadTags();
  },

  methods: {
    async loadTags() {
      this.loading = true;
      try {
        const response = await axios.get(API.EMBEDDED_TAGS_FLAT());
        // Transform tags to simple strings for NcSelect options
        this.allTags = (response.data.tags || []).map(tag => 
          this.showFullPath ? tag.path : tag.tag
        );
      } catch (error) {
        console.error('Failed to load embedded tags:', error);
        this.allTags = [];
      } finally {
        this.loading = false;
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.embedded-tag-selector {
  width: 100%;

  :deep(.vs__dropdown-menu) {
    max-height: 200px;
  }
}
</style> 