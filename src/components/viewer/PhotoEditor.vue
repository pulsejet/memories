<template>
  <div class="me-photo-editor" @keydown.stop>
    <EditorControls
      ref="editor"
      :key="props.photo.fileid"
      :image-width="imgW"
      :image-height="imgH"
      @back="onBack"
      @save="onSave"
      @change="onChange"
      @section="onSection"
    >
      <template #preview>
        <img v-if="!ready" class="me-photo-preview" :src="previewUrl" alt="" />
        <canvas ref="canvasRef" v-show="ready" class="me-photo-canvas" />
      </template>
      <template #filter-thumb="{ filter }">
        <img :src="filterPreviews[filter.id] ?? previewUrl" alt="" />
      </template>
    </EditorControls>

    <PhotoEditorSaveModal
      v-if="saveModalOpen && pendingState"
      :photo="props.photo"
      :state="pendingState"
      :image-width="imgW"
      :image-height="imgH"
      :is-live-photo="isLivePhoto"
      @close="closeSaveModal"
      @saved="onSaved"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';

import { EditPipeline, SmallPreview } from '@radialapps/memories-vips-editor';

import EditorControls from '@components/viewer/editor-controls/EditorControls.vue';
import { stateToEdits } from '@components/viewer/editor-controls/stateToEdits';
import PhotoEditorSaveModal from './PhotoEditorSaveModal.vue';
import { fetchImage } from '@components/frame/XImgCache';

import { fragment } from '@services/route-fragment';
import { t } from '@services/l10n';
import * as utils from '@services/utils/common';
import { confirmDestructive, showError } from '@services/utils/dialog';

import type { IImageInfo, IPhoto } from '@typings';
import { INITIAL_STATE, FILTERS, type EditorState, type EditorSection } from '@components/viewer/editor-controls/types';

const props = defineProps<{
  photo: IPhoto;
}>();

const emit = defineEmits<{
  close: [];
  ready: [];
}>();

const editor = useTemplateRef('editor');
const canvasRef = useTemplateRef<HTMLCanvasElement>('canvasRef');

/** Screen-size preview shown behind the editor controls */
const previewUrl = computed(() => utils.getPreviewUrl({ photo: props.photo, size: 'screen' }));

/** Live WebGL preview pipeline; null until the canvas and source are ready. */
let pipeline: EditPipeline | null = null;

/** Shared downscaled renderer for the filter cards; source set once per photo. */
let smallPreview: SmallPreview | null = null;

/** Live per-filter thumbnails (SmallPreview renders), keyed by preset id. */
const filterPreviews = ref<Record<string, string>>({});

/** True once the source image is on the GPU and the canvas takes over. */
const ready = ref(false);

const imgW = ref(props.photo.w || 0);
const imgH = ref(props.photo.h || 0);

/** Save modal state; opened from EditorControls save (or Ctrl+S). */
const saveModalOpen = ref(false);
const pendingState = ref<EditorState | null>(null);

/** Live photos are always saved as a copy backend-side. */
const isLivePhoto = computed(() => Boolean(props.photo.liveid));

/** Monotonic load generation; drops stale async loads after photo switches. */
let loadGen = 0;
let lastEditsJson = '';

/** Generation guard for async thumbnail builds across photo switches. */
let thumbGen = 0;

/** Preview URL the filter thumbnails were built for; null when stale. */
let previewsForUrl: string | null = null;

/** EditorControls section mirror (starts on 'crop'); drives lazy thumb builds. */
let currentSection: EditorSection = 'crop';

function close() {
  emit('close');
}

/** Whether the editor holds any unsaved edits (hold-to-compare excluded). */
function isDirty(): boolean {
  const s = editor.value?.state;
  if (!s) return false;
  for (const key of Object.keys(INITIAL_STATE) as (keyof EditorState)[]) {
    if (key === 'comparing') continue;
    if (s[key] !== INITIAL_STATE[key]) return true;
  }
  return false;
}

function confirmDiscard(): Promise<boolean> {
  return confirmDestructive({
    title: t('memories', 'Unsaved changes'),
    message: t('memories', 'Your unsaved edits will be lost if you leave the editor.'),
    confirm: t('memories', 'Drop changes'),
    cancel: t('memories', 'Cancel'),
  });
}

async function onBack() {
  if (saveModalOpen.value) {
    closeSaveModal();
    return;
  }
  if (isDirty() && !(await confirmDiscard())) return;
  close();
}

async function onFragmentPop() {
  // Back navigation with the save modal open dismisses the modal first.
  if (saveModalOpen.value) {
    closeSaveModal();
    // The pop already removed the editor fragment; put it back.
    fragment.push(fragment.types.editor);
    return;
  }
  if (isDirty() && !(await confirmDiscard())) {
    // User kept the edits; restore the popped fragment.
    fragment.push(fragment.types.editor);
    return;
  }
  close();
}

/**
 * Every EditorControls mutation arrives here as a full state snapshot.
 * Translate it to a priority-ordered edit list and re-render the preview.
 * `comparing` bypasses the edits to reveal the original image.
 */
function onChange(state: EditorState) {
  if (!pipeline) return;
  try {
    const edits = state.comparing ? [] : stateToEdits(state);
    const json = JSON.stringify(edits);
    if (json === lastEditsJson) return;
    lastEditsJson = json;
    pipeline.setEdits(edits);
  } catch (e) {
    console.error('Photo editor preview failed', e);
  }
}

/**
 * Build filter thumbnails lazily on first opening the filters tab
 * (once per photo); the cards fall back to the plain preview until then.
 */
function onSection(s: EditorSection) {
  currentSection = s;
  if (s === 'filters' && previewsForUrl !== previewUrl.value) {
    void buildFilterPreviews();
  }
}

/**
 * Save the editor state via the vips backend.
 * Opens the save modal (name + format + copy) instead of closing: the modal
 * translates the EditorState to vips edits and PUTs to IMAGE_EDIT.
 */
function onSave(state: EditorState) {
  if (!ready.value) return;
  pendingState.value = { ...state };
  saveModalOpen.value = true;
}

function closeSaveModal() {
  saveModalOpen.value = false;
  pendingState.value = null;
}

function onSaved(_info: IImageInfo, _isCopy: boolean) {
  closeSaveModal();
  close();
}

/** Render one live SmallPreview thumbnail per preset; cards fall back to the plain preview. */
async function buildFilterPreviews() {
  const gen = ++thumbGen;
  try {
    // Full-res screen preview: SmallPreview downscales to <=256 on upload,
    // and the blob is already cached by the main pipeline load.
    const blobUrl = await fetchImage(previewUrl.value);
    if (gen !== thumbGen) return;

    const img = new Image();
    img.src = blobUrl;
    await img.decode();
    if (gen !== thumbGen) return;

    smallPreview ??= new SmallPreview(256);
    smallPreview.setSource(img);
    if (gen !== thumbGen) return;

    // Programs stay compiled across renders; each card pays edit passes only.
    const out: Record<string, string> = {};
    for (const f of FILTERS) {
      if (gen !== thumbGen) return;
      try {
        const canvas = smallPreview.render(stateToEdits({ ...INITIAL_STATE, preset: f.id }));
        out[f.id] = canvas.toDataURL('image/jpeg', 0.8);
      } catch (e) {
        console.error('Photo editor filter preview failed', f.id, e);
      }
    }
    if (gen !== thumbGen) return;
    filterPreviews.value = out;
    previewsForUrl = previewUrl.value;
  } catch (e) {
    console.error('Photo editor filter previews failed', e);
  }
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
    imgW.value = pipeline.width || img.naturalWidth;
    imgH.value = pipeline.height || img.naturalHeight;
    lastEditsJson = '';
    if (editor.value) onChange({ ...editor.value.state });
    ready.value = true;
    emit('ready');
  } catch (e) {
    console.error('Photo editor preview failed', e);
    showError(t('memories', 'Could not load image for editing'));
    close();
  }
}

/** Capture Escape / Ctrl+S while the editor is open (mirrors PhotoSwipe) */
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    e.preventDefault();
    e.stopImmediatePropagation();
    onBack();
  } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    if (!ready.value || saveModalOpen.value || !isDirty()) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    if (editor.value) onSave({ ...editor.value.state });
  }
}

/** Warn on tab close / reload while edits are unsaved. */
function onBeforeUnload(e: BeforeUnloadEvent) {
  if (!isDirty()) return;
  e.preventDefault();
}

onMounted(async () => {
  fragment.push(fragment.types.editor);
  window.addEventListener('keydown', onKeydown, true);
  window.addEventListener('beforeunload', onBeforeUnload);
  await nextTick();
  await loadSource(previewUrl.value);
});

onBeforeUnmount(() => {
  fragment.pop(fragment.types.editor);
  window.removeEventListener('keydown', onKeydown, true);
  window.removeEventListener('beforeunload', onBeforeUnload);
  loadGen++;
  thumbGen++;
  pipeline = null;
  smallPreview?.dispose();
  smallPreview = null;
});

// Reload the source if the edited photo changes underneath the editor.
watch(previewUrl, (url) => {
  closeSaveModal();
  ready.value = false;
  filterPreviews.value = {};
  previewsForUrl = null;
  imgW.value = props.photo.w || 0;
  imgH.value = props.photo.h || 0;
  void loadSource(url);
  if (currentSection === 'filters') void buildFilterPreviews();
});

utils.useBus('memories:fragment:pop:editor', onFragmentPop);
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

.me-photo-preview,
.me-photo-canvas {
  width: 100%;
  height: 100%;
  display: block;
  user-select: none;
  -webkit-user-drag: none;
  pointer-events: none;
}
</style>
