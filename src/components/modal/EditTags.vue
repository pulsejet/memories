<template>
  <div class="outer">
    <NcSelectTags
      ref="selectTags"
      class="nc-comp"
      v-model="tagSelection"
      :label-outside="true"
      :disabled="disabled"
      :limit="null"
      :options-filter="tagFilter"
      :get-option-label="tagLabel"
      :create-option="createOption"
      :taggable="true"
      @option:created="handleCreate"
    />
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

const NcSelectTags = () => import('@nextcloud/vue/dist/Components/NcSelectTags.js');

import * as dav from '@services/dav';

import type { IPhoto } from '@typings';

export default defineComponent({
  name: 'EditTags',
  components: {
    NcSelectTags,
  },

  props: {
    photos: {
      type: Array<IPhoto>,
      required: true,
    },
    disabled: {
      type: Boolean,
      default: false,
    },
  },

  data: () => ({
    origIds: new Set<number>(),
    tagSelection: [] as number[],
    newTags: new Map<number, dav.ITag>(),
  }),

  computed: {
    refs() {
      return this.$refs as {
        selectTags: VueNcSelectTags;
      };
    },
  },

  mounted() {
    this.init();
  },

  methods: {
    init() {
      let tagIds: number[] | null = null;

      // Find common tags in all selected photos
      for (const photo of this.photos) {
        const s = new Set<number>();
        for (const tag of Object.keys(photo.imageInfo?.tags || {}).map(Number)) {
          s.add(tag);
        }
        tagIds = tagIds ? [...tagIds].filter((x) => s.has(x)) : [...s];
      }

      this.tagSelection = tagIds || [];
      this.origIds = new Set(this.tagSelection);
    },

    tagFilter(element: dav.ITag, index: number) {
      return element.displayName !== '' && element.canAssign && element.userAssignable && element.userVisible;
    },

    tagLabel({ displayName }: dav.ITag) {
      return this.t('recognize', displayName);
    },

    createOption(newDisplayName: string): dav.ITag {
      // do not create tags that already exist
      const existing = this.getAvailable().find(
        (x) => x.displayName.toLocaleLowerCase() === newDisplayName.toLocaleLowerCase(),
      );
      if (existing) {
        return existing;
      }

      // placeholder tag
      return {
        userVisible: true,
        userAssignable: true,
        canAssign: true,
        displayName: newDisplayName,
        id: Math.random(),
      };
    },

    getAvailable(): dav.ITag[] {
      // FIXME: this is extremely fragile
      return this.refs.selectTags.availableTags;
    },

    handleCreate(newTag: dav.ITag) {
      this.getAvailable().push(newTag);

      // Keep the new tags around, but only create them when the user clicks save
      // This way we don't create tags that are never used
      this.newTags.set(newTag.id, newTag);
    },

    async result() {
      const add = this.tagSelection.filter((x) => !this.origIds.has(x));
      const remove = [...this.origIds].filter((x) => !this.tagSelection.includes(x));

      // Return null here so there is no useless query
      if (add.length === 0 && remove.length === 0) {
        return null;
      }

      // Create new tags if necessary
      const tagsToAdd = add.map((x) => this.newTags.get(x)).filter((x) => x) as dav.ITag[];
      if (tagsToAdd.length > 0) {
        await Promise.all(
          tagsToAdd.map(async (x: dav.ITag) => {
            // create the actual tag to get the final ID
            const tag = await dav.createTag(x);

            // replace the temporary tag ID with the real one
            const i = add.findIndex((y) => y === x.id);
            add[i] = tag.id;
          }),
        );
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
