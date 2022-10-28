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
import { Component, Mixins, Watch } from "vue-property-decorator";
import GlobalMixin from "../../mixins/GlobalMixin";

import { NcActions, NcActionButton } from "@nextcloud/vue";
import BackIcon from "vue-material-design-icons/ArrowLeft.vue";

@Component({
  components: {
    NcActions,
    NcActionButton,
    BackIcon,
  },
})
export default class TagTopMatter extends Mixins(GlobalMixin) {
  private name: string = "";

  @Watch("$route")
  async routeChange(from: any, to: any) {
    this.createMatter();
  }

  mounted() {
    this.createMatter();
  }

  createMatter() {
    this.name = this.$route.params.name || "";
  }

  back() {
    this.$router.push({ name: "tags" });
  }
}
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