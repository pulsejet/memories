<template>
  <div class="admin-section"></div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Template',
  mixins: [AdminMixin],
});
</script>
