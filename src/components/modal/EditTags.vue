<template>
  <div class="outer">
    <NcSelectTags
      class="nc-comp"
      v-model="tagSelection"
      :limit="null"
      :options-filter="tagFilter"
    />
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";
import { IPhoto } from "../../types";

const NcSelectTags = () =>
  import("@nextcloud/vue/dist/Components/NcSelectTags");

export default defineComponent({
  name: "EditTags",
  components: {
    NcSelectTags,
  },

  props: {
    photos: {
      type: Array<IPhoto>,
      required: true,
    },
  },

  data: () => ({
    origIds: new Set<number>(),
    tagSelection: [] as number[],
  }),

  mounted() {
    this.init();
  },

  methods: {
    init() {
      let tagIds: number[] | null = null;

      // Find common tags in all selected photos
      for (const photo of this.photos) {
        const s = new Set<number>();
        for (const tag of Object.keys(photo.imageInfo?.tags || {}).map(
          Number
        )) {
          s.add(tag);
        }
        tagIds = tagIds ? [...tagIds].filter((x) => s.has(x)) : [...s];
      }

      this.tagSelection = tagIds || [];
      this.origIds = new Set(this.tagSelection);
    },

    tagFilter(element, index) {
      return (
        element.id >= 2 &&
        element.displayName !== "" &&
        element.canAssign &&
        element.userAssignable &&
        element.userVisible
      );
    },

    result() {
      const add = this.tagSelection.filter((x) => !this.origIds.has(x));
      const remove = [...this.origIds].filter(
        (x) => !this.tagSelection.includes(x)
      );

      if (add.length === 0 && remove.length === 0) {
        return null;
      }

      return { add, remove };
    },
  },
});
</script>

<style scoped lang="scss">
.outer {
  margin-top: 10px;

  .nc-comp {
    width: 100%;

    :deep ul {
      max-height: 200px;
    }
  }
}
</style>
