<template>
  <div v-if="name" class="tag-top-matter">
    <NcActions>
      <NcActionButton :aria-label="t('memories', 'Back')" @click="back()">
        {{ t("memories", "Back") }}
        <template #icon> <BackIcon :size="20" /> </template>
      </NcActionButton>
    </NcActions>
    <span class="name">{{ name }}</span>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import NcActions from "@nextcloud/vue/dist/Components/NcActions";
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";

import BackIcon from "vue-material-design-icons/ArrowLeft.vue";

export default defineComponent({
  name: "TagTopMatter",
  components: {
    NcActions,
    NcActionButton,
    BackIcon,
  },

  data: () => ({
    name: "",
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
      this.name = <string>this.$route.params.name || "";

      if (this.$route.name === "places") {
        this.name = this.name.split("-").slice(1).join("-");
      }
    },

    back() {
      this.$router.push({ name: this.$route.name });
    },
  },
});
</script>

<style lang="scss" scoped>
.tag-top-matter {
  .name {
    font-size: 1.3em;
    font-weight: 400;
    line-height: 42px;
    display: inline-block;
    vertical-align: top;
  }

  button {
    display: inline-block;
  }
}
</style>
