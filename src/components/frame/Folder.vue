<template>
  <router-link
    draggable="false"
    class="folder fill-block"
    :class="{
      hasPreview: previews.length > 0,
      onePreview: previews.length === 1,
      hasError: error,
    }"
    :to="target"
  >
    <div class="big-icon fill-block">
      <FolderIcon class="icon" />
      <div class="name">{{ data.name }}</div>
    </div>

    <div class="previews fill-block">
      <div class="preview-container fill-block">
        <div class="img-outer" v-for="info of previews" :key="info.fileid">
          <img
            class="fill-block"
            :src="getPreviewUrl(info, true, 256)"
            @error="$event.target.classList.add('error')"
          />
        </div>
      </div>
    </div>
  </router-link>
</template>

<script lang="ts">
import { defineComponent, PropType } from "vue";
import { IFolder, IPhoto } from "../../types";

import { getPreviewUrl } from "../../services/FileUtils";

import FolderIcon from "vue-material-design-icons/Folder.vue";

export default defineComponent({
  name: "Folder",
  components: {
    FolderIcon,
  },

  props: {
    data: Object as PropType<IFolder>,
  },

  data: () => ({
    // Separate property because the one on data isn't reactive
    previews: [] as IPhoto[],
    // Error occured fetching thumbs
    error: false,
    // Passthrough
    getPreviewUrl,
  }),

  computed: {
    /** Open folder */
    target() {
      const path = this.data.path
        .split("/")
        .filter((x) => x)
        .slice(2) as string[];

      // Remove base path if present
      const basePath = this.config_foldersPath.split("/").filter((x) => x);
      if (
        path.length >= basePath.length &&
        path.slice(0, basePath.length).every((x, i) => x === basePath[i])
      ) {
        path.splice(0, basePath.length);
      }

      return { name: "folders", params: { path: path as any } };
    },
  },

  mounted() {
    this.refreshPreviews();
  },

  watch: {
    data() {
      this.refreshPreviews();
    },
  },

  methods: {
    /** Refresh previews */
    refreshPreviews() {
      // Reset state
      this.error = false;

      // Check if valid path present
      if (!this.data.path) {
        this.error = true;
        return;
      }

      // Get preview infos
      const previews = this.data.previews;
      if (previews) {
        if (previews.length > 0 && previews.length < 4) {
          this.previews = [previews[0]];
        } else {
          this.previews = previews.slice(0, 4);
        }
      }
    },
  },
});
</script>

<style lang="scss" scoped>
.folder {
  cursor: pointer;
}

.big-icon {
  cursor: pointer;
  z-index: 100;
  position: absolute;
  top: 0;
  left: 0;
  transition: opacity 0.2s ease-in-out;

  :deep .material-design-icon__svg {
    width: 50%;
    height: 50%;
  }

  > .name {
    cursor: pointer;
    width: 100%;
    padding: 0 5%;
    text-align: center;
    font-size: 1.08em;
    word-wrap: break-word;
    text-overflow: ellipsis;
    max-height: 35%;
    line-height: 1em;
    position: absolute;
    top: 65%;

    @media (max-width: 768px) {
      font-size: 0.95em;
    }
  }

  // Make it white if there is a preview
  .folder.hasPreview > & {
    .folder-icon {
      opacity: 1;
      filter: invert(1) brightness(100);
    }
    .name {
      color: white;
    }
  }

  // Show it on hover if not a preview
  .folder:hover > & > .folder-icon {
    opacity: 0.8;
  }
  .folder.hasPreview:hover > & {
    opacity: 0;
  }

  // Make it red if has an error
  .folder.hasError > & {
    .folder-icon {
      filter: invert(12%) sepia(62%) saturate(5862%) hue-rotate(8deg)
        brightness(103%) contrast(128%);
    }
    .name {
      color: #bb0000;
    }
  }

  > .folder-icon {
    cursor: pointer;
    height: 90%;
    width: 100%;
    opacity: 0.3;
  }
}

.previews {
  z-index: 3;
  line-height: 0;
  position: absolute;
  padding: 2px;
  box-sizing: border-box;

  .preview-container {
    border-radius: 10px;
    overflow: hidden;
  }

  .img-outer {
    background-color: var(--color-background-dark);
    padding: 0;
    margin: 0;
    width: 50%;
    height: 50%;
    display: inline-block;

    .folder.onePreview > & {
      width: 100%;
      height: 100%;
    }

    > img {
      object-fit: cover;
      padding: 0;
      filter: brightness(50%);
      transition: filter 0.2s ease-in-out;

      &.error {
        display: none;
      }
      .folder:hover & {
        filter: brightness(100%);
      }
    }
  }
}
</style>