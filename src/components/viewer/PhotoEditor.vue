<template>
  <div class="me-photo-editor">
    <EditorControls ref="editor" @back="close" @save="onSave" @change="onChange">
      <template #preview>
        <img v-if="!ready" class="me-photo-preview" :src="previewUrl" alt="" />
        <canvas ref="canvasRef" v-show="ready" class="me-photo-canvas" />
      </template>
      <template #filter-thumb>
        <img :src="thumbUrl" alt="" />
      </template>
    </EditorControls>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';

import { EditPipeline } from '@radialapps/memories-vips-editor';

import EditorControls from '@components/viewer/editor-controls/EditorControls.vue';
import { stateToEdits } from '@components/viewer/editor-controls/stateToEdits';
import { fetchImage } from '@components/frame/XImgCache';

import { fragment } from '@services/route-fragment';
import * as utils from '@services/utils/common';

import type { IPhoto } from '@typings';
import type { EditorState } from '@components/viewer/editor-controls/types';

const props = defineProps<{
  photo: IPhoto;
}>();

const emit = defineEmits<{
  close: [];
}>();

const editor = useTemplateRef('editor');
const canvasRef = useTemplateRef<HTMLCanvasElement>('canvasRef');

/** Screen-size preview shown behind the editor controls */
const previewUrl = computed(() => utils.getPreviewUrl({ photo: props.photo, size: 'screen' }));
/** Small preview for the editor filter preset cards */
const thumbUrl = computed(() => utils.getPreviewUrl({ photo: props.photo, msize: 256 }));

/** Live WebGL preview pipeline; null until the canvas and source are ready. */
let pipeline: EditPipeline | null = null;

/** True once the source image is on the GPU and the canvas takes over. */
const ready = ref(false);

/** Monotonic load generation; drops stale async loads after photo switches. */
let loadGen = 0;

function close() {
  emit('close');
}

/**
 * Every EditorControls mutation arrives here as a full state snapshot.
 * Translate it to a priority-ordered edit list and re-render the preview.
 * `comparing` bypasses the edits to reveal the original image.
 */
function onChange(state: EditorState) {
  if (!pipeline) return;
  try {
    pipeline.setEdits(state.comparing ? [] : stateToEdits(state));
  } catch (e) {
    console.error('Photo editor preview failed', e);
  }
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

/** Upload the current photo into the pipeline (CORS-safe via blob cache). */
async function loadSource(url: string) {
  const gen = ++loadGen;
  const canvas = canvasRef.value;
  if (!canvas) return;

  try {
    // Fetch through the viewer blob cache: same bytes the viewer already
    // decoded, and blob: URLs upload to WebGL without tainting the canvas.
    const blobUrl = await fetchImage(url);
    if (gen !== loadGen || !canvasRef.value) return;

    const img = new Image();
    img.src = blobUrl;
    await img.decode();
    if (gen !== loadGen) return;

    pipeline ??= new EditPipeline(canvas);
    pipeline.setImage(img);
    if (editor.value) onChange({ ...editor.value.state });
    ready.value = true;
  } catch (e) {
    console.error('Photo editor preview failed', e);
  }
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

onMounted(async () => {
  fragment.push(fragment.types.editor);
  window.addEventListener('keydown', onKeydown, true);
  await nextTick();
  await loadSource(previewUrl.value);
});

onBeforeUnmount(() => {
  fragment.pop(fragment.types.editor);
  window.removeEventListener('keydown', onKeydown, true);
  loadGen++;
  pipeline = null;
});

// Reload the source if the edited photo changes underneath the editor.
watch(previewUrl, (url) => {
  ready.value = false;
  void loadSource(url);
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

// The pipeline auto-sizes the canvas backing store to the rendered output;
// CSS just makes it fill all available space in the preview area.
.me-photo-canvas {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}
</style>
