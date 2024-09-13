<template>
  <div
    v-bind="themeDataAttr"
    ref="editor"
    class="viewer__image-editor top-left fill-block"
    :class="{ loading: !imageEditor }"
  ></div>
</template>

<script lang="ts">
import { defineComponent, type PropType } from 'vue';

import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';
import { getLanguage } from '@nextcloud/l10n';

import type { FilerobotImageEditorConfig } from 'react-filerobot-image-editor';

import translations from './ImageEditorTranslations';

import { fetchImage } from '@components/frame/XImgCache';

import { API } from '@services/API';
import * as utils from '@services/utils';

import type { IImageInfo, IPhoto } from '@typings';

let TABS: any, TOOLS: any;
type FilerobotImageEditor = import('filerobot-image-editor').default;
let FilerobotImageEditor: typeof import('filerobot-image-editor').default;

async function loadFilerobot() {
  if (!FilerobotImageEditor) {
    FilerobotImageEditor = (await import('filerobot-image-editor')).default;
    TABS = (<any>FilerobotImageEditor).TABS;
    TOOLS = (<any>FilerobotImageEditor).TOOLS;
  }
  return FilerobotImageEditor;
}

export default defineComponent({
  props: {
    photo: {
      type: Object as PropType<IPhoto>,
      required: true,
    },
  },

  emits: {
    close: () => true,
  },

  data: () => ({
    exif: null as Object | null,
    imageEditor: null as FilerobotImageEditor | null,
  }),

  computed: {
    refs() {
      return this.$refs as {
        editor?: HTMLDivElement;
      };
    },

    config(): FilerobotImageEditorConfig & { theme: any } {
      return {
        source:
          this.photo.h && this.photo.w
            ? utils.getPreviewUrl({ photo: this.photo, size: 'screen' })
            : API.IMAGE_DECODABLE(this.photo.fileid, this.photo.etag),

        defaultSavedImageName: this.defaultSavedImageName,
        defaultSavedImageType: this.defaultSavedImageType,
        // We use our own translations
        useBackendTranslations: false,

        // Watch resize
        observePluginContainerSize: true,

        // Default tab and tool
        defaultTabId: TABS.ADJUST,
        defaultToolId: TOOLS.CROP,

        // Displayed tabs, disabling watermark and draw
        tabsIds: Object.values(TABS)
          .filter((tab) => ![TABS.WATERMARK, TABS.ANNOTATE].includes(tab))
          .sort((a: string, b: string) => a.localeCompare(b, getLanguage())) as any[],

        onClose: this.onClose,
        onSave: this.onSave,

        Rotate: {
          angle: 90,
          componentType: 'buttons',
        },

        // Translations
        translations,

        theme: {
          palette: {
            'bg-secondary': 'var(--color-main-background)',
            'bg-primary': 'var(--color-background-dark)',
            'bg-hover': 'var(--color-background-hover)',
            'bg-stateless': 'var(--color-background-dark)',

            'accent-primary': 'var(--color-primary)',
            'accent-stateless': 'var(--color-primary-element)',
            'border-active-bottom': 'var(--color-primary)',

            'bg-primary-active': 'var(--color-background-dark)',
            'bg-primary-hover': 'var(--color-background-hover)',
            'accent-primary-active': 'var(--color-main-text)',
            'accent-primary-hover': 'var(--color-primary)',

            warning: 'var(--color-error)',
          },
          typography: {
            fontFamily: 'var(--font-face)',
          },
        },

        savingPixelRatio: window.devicePixelRatio,
        previewPixelRatio: window.devicePixelRatio,
      };
    },

    defaultSavedImageName(): string {
      return this.photo.basename || '';
    },

    defaultSavedImageType(): 'jpeg' | 'png' | 'webp' {
      if (['image/jpeg', 'image/png', 'image/webp'].includes(this.photo.mimetype!)) {
        return this.photo.mimetype!.split('/')[1] as any;
      }
      return 'jpeg';
    },

    hasHighContrastEnabled(): boolean {
      const themes = globalThis.OCA?.Theming?.enabledThemes || [];
      return themes.find((theme: any) => theme.indexOf('highcontrast') !== -1);
    },

    themeDataAttr(): Record<string, boolean> {
      if (this.hasHighContrastEnabled) {
        return {
          'data-theme-dark-highcontrast': true,
        };
      }
      return {
        'data-theme-dark': true,
      };
    },
  },

  async mounted() {
    await loadFilerobot();

    const div = this.refs.editor!;
    console.assert(div, 'ImageEditor container not found');

    // Directly use an HTML element to make sure the resolution
    // in the editor matches the original file, but we can work
    // with a preview instead
    const source = await this.getImage();
    const config = { ...this.config, source };

    // Add observer to update nodes as added
    new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (!(node instanceof Element)) return;

          node.querySelectorAll('.FIE_tools-bar button').forEach((node) => {
            // Do not apply parent styles
            node.classList.add('button-vue');
          });
        });
      });
    }).observe(div, { childList: true, subtree: true });

    // Create the editor
    this.imageEditor = new FilerobotImageEditor(div, config);
    this.imageEditor.render();

    // Handle keyboard
    window.addEventListener('keydown', this.handleKeydown, true);

    // Fragment navigation
    utils.fragment.push(utils.fragment.types.editor);
    utils.bus.on('memories:fragment:pop:editor', this.warnUnsaved);
  },

  beforeDestroy() {
    // Cleanup
    this.imageEditor?.terminate();

    // Remove keyboard handler
    window.removeEventListener('keydown', this.handleKeydown, true);

    // Fragment navigation
    utils.fragment.pop(utils.fragment.types.editor);
    utils.bus.off('memories:fragment:pop:editor', this.warnUnsaved);
  },

  methods: {
    async getImage(): Promise<HTMLImageElement> {
      const img = new Image();
      img.name = this.defaultSavedImageName;

      await new Promise(async (resolve) => {
        img.onload = resolve;
        img.src = await fetchImage(<string>this.config.source);
      });

      if (this.photo.w && this.photo.h) {
        img.height = this.photo.h;
        img.width = this.photo.w;
      }

      return img;
    },

    onClose(closingReason: any, haveNotSavedChanges: boolean) {
      // Prevent the hook from being called again since we
      // are going to quit now
      utils.bus.off('memories:fragment:pop:editor', this.warnUnsaved);

      // Cleanup
      this.imageEditor?.terminate();
      window.removeEventListener('keydown', this.handleKeydown, true);
      this.$emit('close');
    },

    /**
     * User saved the image
     *
     * @see https://github.com/scaleflex/filerobot-image-editor#onsave
     */
    async onSave(
      data: {
        name: string;
        extension: string;
        width?: number;
        height?: number;
        quality?: number;
        fullName?: string;
        imageBase64?: string;
      },
      state: any,
    ): Promise<void> {
      // Copy state
      state = structuredClone(state);

      // Convert crop to relative values
      if (state?.adjustments?.crop) {
        const iw = state.shownImageDimensions.width;
        const ih = state.shownImageDimensions.height;
        const { x, y, width, height } = state.adjustments.crop;
        state.adjustments.crop = {
          x: x / iw,
          y: y / ih,
          width: width / iw,
          height: height / ih,
        };
      }

      // Make sure we have an extension
      let name = data.name;
      const nameLower = name.toLowerCase();
      if (!nameLower.endsWith(data.extension) && !nameLower.endsWith('.jpg')) {
        name += '.' + data.extension;
      }

      try {
        const res = await axios.put<IImageInfo>(API.IMAGE_EDIT(this.photo.fileid), {
          name: name,
          width: data.width,
          height: data.height,
          quality: data.quality,
          extension: data.extension,
          state: state,
        });
        const fileid = res.data.fileid;

        // Success, emit an appropriate event
        showSuccess(this.t('memories', 'Image saved successfully'));

        if (fileid !== this.photo.fileid) {
          utils.bus.emit('files:file:created', { fileid });
        } else {
          utils.updatePhotoFromImageInfo(this.photo, res.data);
          utils.bus.emit('files:file:updated', { fileid });
        }
        this.onClose(undefined, false);
      } catch (err) {
        showError(
          this.t('memories', 'Error saving image: {error}', {
            error: err?.response?.data?.message ?? err?.message ?? this.t('memories', 'Unknown'),
          }),
        );
        console.error(err);
      }
    },

    /** Show warning for unsaved changes */
    async warnUnsaved() {
      // This method is only used when pressing the back button

      // To find whether there are unsaved changes, just check
      // if the reset button is enabled
      const noChanges = this.refs.editor?.querySelector('button[title="Reset"]')?.hasAttribute('disabled');

      if (
        noChanges ||
        (await utils.confirmDestructive({
          title: this.t('memories', 'Unsaved changes'),
          message: translations.discardChangesWarningHint,
          confirm: this.t('memories', 'Drop changes'),
          confirmClasses: 'error',
          cancel: translations.cancel,
        }))
      ) {
        this.onClose('warning-ignored', false);
      } else {
        // User cancelled, put the fragment back
        utils.fragment.push(utils.fragment.types.editor);
      }
    },

    // Key Handlers, override default Viewer arrow and escape key
    handleKeydown(event: KeyboardEvent) {
      event.stopImmediatePropagation();
      // escape key
      if (event.key === 'Escape') {
        event.preventDefault();
        this.close();
      }

      // ctrl + S = save
      if (event.ctrlKey && event.key === 's') {
        event.preventDefault();
        (document.querySelector('.FIE_topbar-save-button') as HTMLElement)?.click();
      }

      // ctrl + Z = undo
      if (event.ctrlKey && event.key === 'z') {
        event.preventDefault();
        (document.querySelector('.FIE_topbar-undo-button') as HTMLElement)?.click();
      }
    },

    close() {
      // Since we cannot call the closeMethod and know if there
      // are unsaved changes, let's fake a close button trigger.
      (document.querySelector('.FIE_topbar-close-button') as HTMLElement)?.click();
    },
  },
});
</script>

<style lang="scss" scoped>
// Take full screen size ()
.viewer__image-editor {
  z-index: 10100;
  background-color: black;
}
</style>

<style lang="scss">
// Make sure the editor and its modals are above everything
.SfxModal-Wrapper {
  z-index: 10101 !important;
}

.SfxPopper-wrapper {
  z-index: 10102 !important;
}

.viewer__image-editor {
  label,
  button {
    color: var(--color-main-text);
  }
}

.FIE_canvas-node {
  background: none !important;
}

// Input styling
.SfxInput-root {
  height: auto !important;
  padding: 0 !important;
  background: none !important;
  border: none !important;
  .SfxInput-Base {
    margin: 0 !important;
    min-height: 0 !important;
    height: 28px !important;
    font-size: 0.85em !important;

    .FIE_tool-options-wrapper & {
      padding: 0 !important;
    }
  }
}

// Select styling
.SfxSelect-root {
  padding: 8px !important;
  line-height: initial !important;
}

.SfxButton-root {
  min-height: 0 !important;
  border: none !important;

  &[color='error'],
  &[color='warning-primary'] {
    color: white !important;
    background-color: var(--color-error) !important;
    &:hover,
    &:focus {
      border-color: white !important;
      background-color: var(--color-error-hover) !important;
    }
  }

  &[color='primary'] {
    color: var(--color-primary-text) !important;
    background-color: var(--color-primary-element) !important;
    &:hover,
    &:focus {
      background-color: var(--color-primary-element-hover) !important;
    }
  }
}

// Menu items
.SfxMenuItem-root {
  &[value='jpeg'] {
    // Disable jpeg saving (jpg is already here)
    display: none;
  }
}

.SfxModal-Container {
  .SfxModalTitle-root {
    color: var(--color-main-text) !important;
  }

  .SfxModalTitle-Icon {
    background: none !important;
    padding: 0 !important;

    svg {
      width: 64px;
      height: 64px;
      opacity: 0.4;
      --color-primary: var(--color-main-text);
      --color-error: var(--color-main-text);
    }
  }

  // Hide close icon (use cancel button)
  .SfxModalTitle-Close {
    display: none !important;
  }
  // Modal actions buttons display
  .SfxModalActions-root {
    justify-content: space-evenly !important;
  }

  .SfxSlider-root {
    margin-top: 10px;
  }
}

.FIE_tabs {
  box-shadow: none !important;
}

.FIE_tab {
  &:hover,
  &:focus {
    background-color: var(--color-background-hover) !important;
  }

  &[aria-selected='true'] {
    color: var(--color-main-text);
    background-color: var(--color-background-dark);
    box-shadow: 0 0 0 2px var(--color-primary-element);
  }
}

[data-phone='true'] .FIE_topbar {
  padding-top: 8px !important;
  padding-bottom: 6px !important;
}

.FIE_topbar-history-buttons button,
.FIE_topbar-close-button,
.FIE_resize-ratio-locker {
  border: none !important;
  background-color: transparent !important;

  &:hover,
  &:focus {
    background-color: var(--color-background-hover) !important;
  }
}

// Save button fixes
.FIE_topbar-save-button {
  color: var(--color-primary-text) !important;
  border: none !important;
  background-color: var(--color-primary-element) !important;
  &:hover,
  &:focus {
    background-color: var(--color-primary-element-hover) !important;
  }
}

.FIE_filters-item {
  cursor: pointer;
  .FIE_filters-item-preview,
  .konvajs-content {
    pointer-events: none;
  }

  &[aria-selected='true'] .FIE_filters-item-preview {
    padding: 0 !important;
    border: none !important;
    outline: 1px solid var(--color-main-text) !important;
  }
}

.FIE_carousel-prev-button,
.FIE_carousel-next-button {
  width: 30px !important;
  background: rgba(0, 0, 0, 0.5) !important;
  padding: 5px !important;
  svg {
    color: white !important;
    transform: scale(1.25) !important;
  }
}

.FIE_spinner-wrapper {
  background-color: var(--color-main-background) !important;
}
</style>
