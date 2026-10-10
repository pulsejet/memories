<template>
  <div
    v-bind="themeDataAttr"
    ref="editor"
    class="viewer__image-editor top-left fill-block"
    :class="{ loading: !imageEditor }"
  ></div>
</template>

<script setup lang="ts">
import { computed, markRaw, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import { useMutationObserver } from '@vueuse/core';

import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@services/utils/dialog';
import { getLanguage } from '@nextcloud/l10n';

import type { FilerobotImageEditorConfig } from 'react-filerobot-image-editor';

import translations from './ImageEditorTranslations';

import { fetchImage } from '@components/frame/XImgCache';

import { API } from '@services/API';
import { t } from '@services/l10n';
import * as utils from '@services/utils/common';

import type { IImageInfo, IPhoto } from '@typings';

let TABS: Record<string, any>, TOOLS: Record<string, any>;
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

const props = defineProps<{
  photo: IPhoto;
}>();

const emit = defineEmits<{
  close: [];
}>();

const editor = useTemplateRef<HTMLDivElement>('editor');

const imageEditor = ref<FilerobotImageEditor | null>(null);

const config = computed((): FilerobotImageEditorConfig & { theme: any } => {
  return {
    source:
      props.photo.h && props.photo.w
        ? utils.getPreviewUrl({ photo: props.photo, size: 'screen' })
        : API.IMAGE_DECODABLE(props.photo.fileid, props.photo.etag),

    defaultSavedImageName: defaultSavedImageName.value,
    defaultSavedImageType: defaultSavedImageType.value,
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

    onClose: onClose,
    onSave: onSave,

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
});

const defaultSavedImageName = computed((): string => {
  return props.photo.basename || '';
});

const defaultSavedImageType = computed((): 'jpg' | 'png' | 'webp' => {
  if (['image/png', 'image/webp'].includes(props.photo.mimetype!)) {
    return props.photo.mimetype!.split('/')[1] as any;
  }
  return 'jpg';
});

const hasHighContrastEnabled = computed((): boolean => {
  const themes = globalThis.OCA?.Theming?.enabledThemes || [];
  return themes.some((theme: string) => theme.includes('highcontrast'));
});

const themeDataAttr = computed((): Record<string, boolean> => {
  if (hasHighContrastEnabled.value) {
    return {
      'data-theme-dark-highcontrast': true,
    };
  }
  return {
    'data-theme-dark': true,
  };
});

onMounted(async () => {
  // Directly use an HTML element to make sure the resolution
  // in the editor matches the original file, but we can work
  // with a preview instead
  let source: HTMLImageElement;
  try {
    await loadFilerobot();
    source = await getImage();
  } catch (error) {
    console.error(error);
    showError(t('memories', 'Failed to load image'));
    emit('close');
    return;
  }

  const div = editor.value;
  if (!div) return;
  const editorConfig = { ...config.value, source };

  // Create the editor
  imageEditor.value = markRaw(new FilerobotImageEditor(div, editorConfig));
  imageEditor.value.render();

  // Handle keyboard
  window.addEventListener('keydown', handleKeydown, true);

  // Fragment navigation
  utils.fragment.push(utils.fragment.types.editor);
});

onBeforeUnmount(() => {
  // Cleanup
  imageEditor.value?.terminate();

  // Remove keyboard handler
  window.removeEventListener('keydown', handleKeydown, true);

  // Fragment navigation
  utils.fragment.pop(utils.fragment.types.editor);
});

utils.useBus('memories:fragment:pop:editor', warnUnsaved);

// Prevent editor buttons from being styled by global CSS
useMutationObserver(
  editor,
  (mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) return;
        node.querySelectorAll('.FIE_tools-bar button').forEach((node) => {
          node.classList.add('button-vue');
        });
      });
    });
  },
  { childList: true, subtree: true },
);

async function getImage(): Promise<HTMLImageElement> {
  const img = new Image();
  img.name = defaultSavedImageName.value;

  const src = await fetchImage(<string>config.value.source);
  await new Promise<void>((resolve, reject) => {
    img.onload = () => resolve();
    img.onerror = () => reject(new Error('Failed to load image'));
    img.src = src;
  });

  if (props.photo.w && props.photo.h) {
    img.height = props.photo.h;
    img.width = props.photo.w;
  }

  return img;
}

function onClose(closingReason: any, haveNotSavedChanges: boolean) {
  // Prevent the hook from being called again since we
  // are going to quit now
  utils.bus.off('memories:fragment:pop:editor', warnUnsaved);

  // Cleanup
  imageEditor.value?.terminate();
  imageEditor.value = null;
  window.removeEventListener('keydown', handleKeydown, true);
  emit('close');
}

/**
 * User saved the image
 *
 * @see https://github.com/scaleflex/filerobot-image-editor#onsave
 */
async function onSave(
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

  // Suffix a different format so it saves as a copy
  // https://github.com/pulsejet/memories/issues/1611
  let name = data.name;
  const nameLower = name.toLowerCase();
  const ext = data.extension.toLowerCase() === 'jpeg' ? 'jpg' : data.extension.toLowerCase();
  if (!nameLower.endsWith('.' + ext) && !(ext === 'jpg' && nameLower.endsWith('.jpeg'))) {
    name += '.' + data.extension;
  }

  try {
    const res = await axios.put<IImageInfo>(API.IMAGE_EDIT(props.photo.fileid), {
      name: name,
      width: data.width,
      height: data.height,
      quality: data.quality,
      extension: data.extension,
      state: state,
    });
    const fileid = res.data.fileid;

    // Success, emit an appropriate event
    showSuccess(t('memories', 'Image saved successfully'));

    if (fileid !== props.photo.fileid) {
      utils.bus.emit('files:file:created', { fileid });
    } else {
      utils.updatePhotoFromImageInfo(props.photo, res.data);
      utils.bus.emit('files:file:updated', { fileid });
    }
    onClose(undefined, false);
  } catch (err: any) {
    showError(
      t('memories', 'Error saving image: {error}', {
        error: err?.response?.data?.message ?? err?.message ?? t('memories', 'Unknown'),
      }),
    );
    console.error(err);
  }
}

/** Show warning for unsaved changes */
async function warnUnsaved() {
  // This method is only used when pressing the back button

  // To find whether there are unsaved changes, just check
  // if the reset button is enabled
  const noChanges = editor.value?.querySelector('button[title="Reset"]')?.hasAttribute('disabled');

  if (
    noChanges ||
    (await utils.confirmDestructive({
      title: t('memories', 'Unsaved changes'),
      message: translations.discardChangesWarningHint,
      confirm: t('memories', 'Drop changes'),
      confirmClasses: 'error',
      cancel: translations.cancel,
    }))
  ) {
    onClose('warning-ignored', false);
  } else {
    // User cancelled, put the fragment back
    utils.fragment.push(utils.fragment.types.editor);
  }
}

// Key Handlers, override default Viewer arrow and escape key
function handleKeydown(event: KeyboardEvent) {
  event.stopImmediatePropagation();
  // escape key
  if (event.key === 'Escape') {
    event.preventDefault();
    close();
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
}

function close() {
  // Since we cannot call the closeMethod and know if there
  // are unsaved changes, let's fake a close button trigger.
  (document.querySelector('.FIE_topbar-close-button') as HTMLElement)?.click();
}
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
