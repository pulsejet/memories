<template>
  <NcEmptyContent
    :title="t('memories', 'Nothing to show here')"
    :description="emptyViewDescription"
  >
    <template #icon>
      <PeopleIcon v-if="routeIsPeople" />
      <ArchiveIcon v-else-if="routeIsArchive" />
      <ImageMultipleIcon v-else />
    </template>
  </NcEmptyContent>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import NcEmptyContent from "@nextcloud/vue/dist/Components/NcEmptyContent";

import PeopleIcon from "vue-material-design-icons/AccountMultiple.vue";
import ImageMultipleIcon from "vue-material-design-icons/ImageMultiple.vue";
import ArchiveIcon from "vue-material-design-icons/PackageDown.vue";

import * as strings from "../../services/strings";

export default defineComponent({
  name: "EmptyContent",

  components: {
    NcEmptyContent,

    PeopleIcon,
    ArchiveIcon,
    ImageMultipleIcon,
  },

  computed: {
    emptyViewDescription(): string {
      return strings.emptyDescription(this.$route.name);
    },

    routeIsPeople(): boolean {
      return (
        this.$route.name === "recognize" ||
        this.$route.name === "facerecognition"
      );
    },

    routeIsArchive(): boolean {
      return this.$route.name === "archive";
    },
  },
});
</script>
