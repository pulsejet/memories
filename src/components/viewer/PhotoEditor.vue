<template>
  <div class="me-photo-editor">
    <EditorControls ref="editor" @back="close" @save="onSave">
      <template #preview>
        <img class="me-photo-preview" :src="previewUrl" alt="" />
      </template>
      <template #filter-thumb>
        <img :src="thumbUrl" alt="" />
      </template>
    </EditorControls>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, useTemplateRef } from 'vue';

import EditorControls from '@components/viewer/editor-controls/EditorControls.vue';
import type { EditorState } from '@components/viewer/editor-controls/types';

import { fragment } from '@services/route-fragment';
import * as utils from '@services/utils/common';

import type { IPhoto } from '@typings';

const props = defineProps<{
  photo: IPhoto;
}>();

const emit = defineEmits<{
  close: [];
}>();

const editor = useTemplateRef('editor');

/** Screen-size preview shown behind the editor controls */
const previewUrl = computed((): string => utils.getPreviewUrl({ photo: props.photo, size: 'screen' }));

/** Small preview for the editor filter preset cards */
const thumbUrl = computed((): string => utils.getPreviewUrl({ photo: props.photo, msize: 256 }));

function close() {
  emit('close');
}

/**
 * Save the editor state.
 * TODO: translate EditorState to the backend edit payload. The filerobot
 * state shape consumed by FileRobotMagick no longer exists, so saving is
 * currently a no-op that just closes the editor.
 */
function onSave(_state: EditorState) {
  close();
}

/** Capture Escape / Ctrl+S while the editor is open (mirrors PhotoSwipe) */
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    e.preventDefault();
    e.stopImmediatePropagation();
    close();
  } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault();
    e.stopImmediatePropagation();
    if (editor.value) onSave({ ...editor.value.state });
  }
}

onMounted(() => {
  fragment.push(fragment.types.editor);
  window.addEventListener('keydown', onKeydown, true);
});

onBeforeUnmount(() => {
  fragment.pop(fragment.types.editor);
  window.removeEventListener('keydown', onKeydown, true);
});

utils.useBus('memories:fragment:pop:editor', close);
</script>

<style lang="scss" scoped>
.me-photo-editor {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 10100;
  background-color: black;
}

.me-photo-preview {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
</style>
