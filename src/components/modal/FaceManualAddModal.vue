<template>
  <Modal ref="modal" @close="cleanup" v-if="show" size="large">
    <template #title>
      {{ t('memories', 'Add person') }}
    </template>

    <div class="manual-face-add">
      <!-- Step 1: pick a file -->
      <div v-if="!fileId" class="picker-step">
        <NcNoteCard v-if="loadError" type="error">{{ loadError }}</NcNoteCard>
        <p>{{ t('memories', 'Choose a photo containing the person you want to tag.') }}</p>
        <NcButton variant="primary" @click="pickFile">
          {{ t('memories', 'Choose photo') }}
        </NcButton>
      </div>

      <!-- Step 2: draw rectangles -->
      <div v-else class="draw-step">
        <!-- Suggestions of already-known person names for the name fields below -->
        <datalist id="memories-manual-face-names">
          <option v-for="known in knownNames" :key="known" :value="known" />
        </datalist>

        <NcNoteCard v-if="loadError" type="error">
          {{ loadError }}
          <NcButton variant="tertiary" @click="loadFaces">{{ t('memories', 'Try again') }}</NcButton>
        </NcNoteCard>

        <NcNoteCard v-if="!dimensionsKnown" type="warning">
          {{
            t(
              'memories',
              'The original size of this photo is not known yet, so a marking would end up in the wrong place. Faces can be marked on it once the photo has been indexed.',
            )
          }}
        </NcNoteCard>

        <NcNoteCard v-if="legacy" type="info">
          {{
            t(
              'memories',
              'Face Recognition on the server does not report the state of the faces. Marking without a name, searching an area and moving a whole group become available once it is updated.',
            )
          }}
        </NcNoteCard>

        <NcNoteCard v-if="navigationError" type="info">
          {{
            t(
              'memories',
              'Zooming with two fingers is not available here. Drawing, the buttons and the mouse wheel still work.',
            )
          }}
        </NcNoteCard>

        <p class="hint">{{ hint }}</p>

        <div class="stage-wrap">
          <FaceMarkingStage
            :src="imageSrc"
            :faces="stageFaces"
            :regions="stageRegions"
            :rect="rect"
            :drawing-enabled="canDraw"
            @update:rect="onRect"
            @select="selectFace"
            @navigation-error="navigationError = true"
            @image-error="loadError = t('memories', 'The photo could not be loaded.')"
          />
          <transition name="saved-notice">
            <div v-if="notice" class="saved-notice" role="status">{{ notice }}</div>
          </transition>
        </div>

        <div class="legend">
          <span><i class="swatch origin-auto" />{{ t('memories', 'Found automatically') }}</span>
          <span><i class="swatch origin-manual" />✎ {{ t('memories', 'Marked by hand') }}</span>
          <template v-if="!legacy">
            <span><i class="swatch line-participating" />{{ t('memories', 'Used for recognition') }}</span>
            <span><i class="swatch line-pending" />{{ t('memories', 'Waiting') }}</span>
            <span><i class="swatch line-excluded" />{{ t('memories', 'Not used') }}</span>
            <span><i class="swatch ignored" />{{ t('memories', 'Ignored') }}</span>
          </template>
        </div>

        <FaceRegionList
          v-if="regions && regions.length"
          :regions="regions"
          @highlight="highlightedRegionId = $event"
          @done="onRegionRemoved"
        />

        <!-- A new marking -->
        <div v-if="rect && !selectedFaces.length" class="fields">
          <NcTextField
            ref="nameField"
            class="field"
            v-model="rawInput"
            :label="t('memories', 'Name')"
            :label-visible="false"
            :placeholder="t('memories', 'Name')"
            list="memories-manual-face-names"
            @keypress.enter="saveNamed()"
          />
          <NcNoteCard v-if="saveError" type="error">{{ saveError }}</NcNoteCard>
          <p class="scope">{{ markingScope }}</p>
          <p v-if="canSearchRegion" class="scope">{{ t('memories', 'Or search the area:') }} {{ regionScope }}</p>
        </div>

        <!-- Faces that are there, one or several -->
        <FaceSelectionPanel
          v-if="selectedFaces.length"
          ref="panel"
          :faces="selectedFaces"
          :limits="limits"
          :legacy="legacy"
          :known-names="knownNames"
          @done="onSelectionDone"
          @cancel="clearSelection"
        />
      </div>
    </div>

    <template #buttons>
      <NcButton v-if="fileId && !rect && !selectedFaces.length" @click="resetFile">
        {{ t('memories', 'Choose different photo') }}
      </NcButton>

      <template v-if="rect && !selectedFaces.length">
        <NcButton v-if="canSearchRegion" :disabled="saving" @click="saveRegion">
          {{ t('memories', 'Search area for faces') }}
        </NcButton>
        <NcButton v-if="canSaveUnnamed" :disabled="saving" @click="saveUnnamed">
          {{ t('memories', 'Save without name') }}
        </NcButton>
        <NcButton class="button" variant="primary" :disabled="!canSaveNamed" @click="saveNamed">
          {{ t('memories', 'Save') }}
        </NcButton>
      </template>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import axios from '@nextcloud/axios';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
import NcTextField from '@nextcloud/vue/components/NcTextField';

import Modal from './Modal.vue';
import FaceMarkingStage from './FaceMarkingStage.vue';
import FaceSelectionPanel from './FaceSelectionPanel.vue';
import FaceRegionList from './FaceRegionList.vue';

import { API } from '@services/API';
import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import * as utils from '@services/utils/common';
import {
  faceRecognitionAddManualFace,
  faceRecognitionAddManualRegion,
  faceRecognitionGetFacesForFile,
  getFaceList,
  type IFaceLimits,
  type IFaceRectForFile,
  type IManualRegion,
} from '@services/dav/face';

import {
  errorText,
  focusWithoutScrolling,
  inputOf,
  markingScope as markingScopeOf,
  regionScope as regionScopeText,
  stageFaceOf,
  stageRegionOf,
  type Rect,
} from './faceMarking';

import type { IImageInfo } from '@typings';

/** How long a saved marking is announced over the photo */
const NOTICE_MS = 4000;

defineOptions({
  name: 'FaceManualAddModal',
});

const emit = defineEmits<{
  /** Faces were added or moved while the dialog was open; sent when it closes */
  added: [];
}>();

const modal = useTemplateRef('modal');
const nameField = useTemplateRef('nameField');
const panel = useTemplateRef<InstanceType<typeof FaceSelectionPanel>>('panel');
const { show } = useModal(modal);

const fileId = ref(0);
const imageSrc = ref('');
const imageNatW = ref(0);
const imageNatH = ref(0);
const faces = ref<IFaceRectForFile[]>([]);
const regions = ref<IManualRegion[] | null>(null);
const limits = ref<IFaceLimits | null>(null);
const legacy = ref(false);
const loadError = ref('');
const rect = ref<Rect | null>(null);
const rawInput = ref('');
const saving = ref(false);
const saveError = ref('');
/** The faces selected, in the order they were picked; several with Ctrl+click */
const selectedFaceIds = ref<number[]>([]);
/** The searched area the pointer is on in the list, shown on the photo */
const highlightedRegionId = ref<number | null>(null);
const knownNames = ref<string[]>([]);
const navigationError = ref(false);
/** What was just saved, shown over the photo for a moment */
const notice = ref('');
let noticeTimer = 0;
/**
 * Whether anything was saved. The sidebar and the timeline are told when
 * the dialog closes, not on each save: the sidebar reloads by dropping
 * its content, and this dialog with it.
 */
let changed = false;

/** The size of the original photo, which every marking is measured against. */
const dimensionsKnown = computed(() => imageNatW.value > 0 && imageNatH.value > 0);

const canDraw = computed(() => dimensionsKnown.value && !saving.value);

const name = computed(() => rawInput.value.trim());

const canSaveNamed = computed(() => !!rect.value && !!name.value && !saving.value && dimensionsKnown.value);

const canSaveUnnamed = computed(() => !legacy.value);

const canSearchRegion = computed(() => !legacy.value && regions.value !== null);

/** The faces selected that are on the photo, in the order they were picked. */
const selectedFaces = computed(() =>
  selectedFaceIds.value
    .map((id) => faces.value.find((face) => face.id === id))
    .filter((face): face is IFaceRectForFile => !!face),
);

const hint = computed(() => {
  if (!dimensionsKnown.value) return '';
  if (selectedFaces.value.length > 1) {
    return t('memories', 'Ctrl+click adds a face or takes it out again. Draw a new rectangle to mark another face.');
  }
  if (selectedFaces.value.length === 1) {
    return t(
      'memories',
      'Draw a new rectangle to mark another face. Ctrl+click selects several faces, to ignore or delete them together.',
    );
  }
  if (rect.value) {
    return t('memories', 'Enter a name, or save without one. You can redraw by dragging again.');
  }
  return t(
    'memories',
    'Drag on the photo to mark a face, or an area with several faces to search again. Zoom with the mouse wheel or two fingers, and move with the middle mouse button or two fingers. Click a face to see its state or rename it, Ctrl+click to select several.',
  );
});

const stageFaces = computed(() => {
  if (!dimensionsKnown.value) return [];
  return faces.value.map((face) =>
    stageFaceOf(face, imageNatW.value, imageNatH.value, limits.value, selectedFaceIds.value.includes(face.id)),
  );
});

const stageRegions = computed(() => {
  if (!dimensionsKnown.value || !regions.value) return [];
  return regions.value.map((region) =>
    stageRegionOf(region, imageNatW.value, imageNatH.value, region.id === highlightedRegionId.value),
  );
});

const markingScope = computed(() => markingScopeOf(name.value, knownNames.value));

const regionScope = computed(() => regionScopeText());

function open() {
  resetAll();
  changed = false;
  show.value = true;
  loadKnownNames();
}

async function openForFile(info: { fileid: number; etag?: string; w?: number; h?: number }) {
  resetAll();
  changed = false;
  show.value = true;
  loadKnownNames();
  if (!info?.fileid) return;

  fileId.value = info.fileid;
  // Only the size of the original is any good: a marking is measured
  // against it, and the preview is scaled down.
  imageNatW.value = info.w ?? 0;
  imageNatH.value = info.h ?? 0;
  imageSrc.value = previewOf(fileId.value, info.etag);
  await loadFaces();
}

function cleanup() {
  show.value = false;
  resetAll();
  if (changed) {
    changed = false;
    emit('added');
  }
}

/** Shows what was just saved over the photo, for a few seconds. */
function showNotice(text: string) {
  window.clearTimeout(noticeTimer);
  notice.value = text;
  noticeTimer = window.setTimeout(() => (notice.value = ''), NOTICE_MS);
}

/** Puts the cursor in the name field of a new marking, without scrolling to it. */
function focusName() {
  nextTick(() => focusWithoutScrolling(() => inputOf(nameField.value)));
}

function previewOf(id: number, etag?: string): string {
  return API.Q(API.IMAGE_PREVIEW(id), { c: etag, x: 2048, y: 2048, a: '1' });
}

/**
 * Load the faces of the photo, with their state, and the regions queued
 * on it. A failure is shown here, and leaves the rest of the app alone.
 */
async function loadFaces(): Promise<void> {
  const id = fileId.value;
  if (!id) return;
  loadError.value = '';
  try {
    const result = await faceRecognitionGetFacesForFile(id);
    // The dialog moved on to another photo, or was closed, meanwhile.
    if (id !== fileId.value) return;
    faces.value = result.faces;
    regions.value = result.regions;
    limits.value = result.limits;
    legacy.value = result.legacy;
    selectedFaceIds.value = selectedFaceIds.value.filter((faceId) => faces.value.some((face) => face.id === faceId));
  } catch (e) {
    console.error(e);
    if (id !== fileId.value) return;
    loadError.value = errorText(e, t('memories', 'The faces of this photo could not be loaded.'));
  }
}

/**
 * Load the names of already-known persons so the name fields can offer
 * autocompletion — mirrors how naming an unknown cluster suggests existing names.
 * Failure is non-fatal: it just means no suggestions are shown.
 */
async function loadKnownNames(): Promise<void> {
  try {
    const list = await getFaceList('facerecognition');
    const names = list
      .map((f) => f.name)
      // Keep only real names; unnamed clusters expose a numeric id as their name.
      .filter((known): known is string => !!known && Number.isNaN(Number(known)));
    knownNames.value = Array.from(new Set(names)).sort((a, b) => a.localeCompare(b));
  } catch (e) {
    console.error(e);
    knownNames.value = [];
  }
}

function resetAll() {
  resetFile();
  knownNames.value = [];
  navigationError.value = false;
}

function resetFile() {
  fileId.value = 0;
  imageSrc.value = '';
  imageNatW.value = 0;
  imageNatH.value = 0;
  faces.value = [];
  regions.value = null;
  limits.value = null;
  legacy.value = false;
  loadError.value = '';
  rect.value = null;
  rawInput.value = '';
  saving.value = false;
  saveError.value = '';
  selectedFaceIds.value = [];
  highlightedRegionId.value = null;
  window.clearTimeout(noticeTimer);
  notice.value = '';
}

async function pickFile(): Promise<void> {
  // @nextcloud/dialogs is large, and loaded on first use only
  const { getFilePickerBuilder } = await import('@services/utils/dialog-lib');
  const picker = getFilePickerBuilder(t('memories', 'Choose photo'))
    .setMultiSelect(false)
    .addMimeTypeFilter('image/jpeg')
    .addMimeTypeFilter('image/png')
    .addMimeTypeFilter('image/webp')
    .addMimeTypeFilter('image/heic')
    .addMimeTypeFilter('image/heif')
    .setType(1)
    .allowDirectories(false)
    .build();

  let path: string;
  try {
    path = (await utils.fragment.wrap(picker.pick(), utils.fragment.types.dialog)) as string;
  } catch (e) {
    return; // user cancelled
  }
  if (!path) return;

  await loadPhotoByPath(path);
}

async function loadPhotoByPath(path: string): Promise<void> {
  let file: { fileid: number; etag: string };
  try {
    // Memories' IMAGE_INFO requires a fileid, so the file is looked up via WebDAV.
    file = await webdavFileInfo(path);
  } catch (e) {
    console.error(e);
    resetFile();
    loadError.value = t('memories', 'Failed to load the selected photo.');
    return;
  }
  const size = await indexedSize(file.fileid);
  fileId.value = file.fileid;
  imageNatW.value = size.w;
  imageNatH.value = size.h;
  imageSrc.value = previewOf(fileId.value, file.etag);
  await loadFaces();
}

/**
 * The size of the original as Memories indexed it, the same the sidebar
 * opens the dialog with. A photo not indexed yet has none: it is shown,
 * but nothing can be marked on it, since the size of the preview would
 * put the marking elsewhere.
 */
async function indexedSize(id: number): Promise<{ w: number; h: number }> {
  try {
    const { data } = await axios.get<IImageInfo>(API.Q(API.IMAGE_INFO(id), { basic: 1 }));
    return { w: data.w || 0, h: data.h || 0 };
  } catch (e) {
    console.warn(e);
    return { w: 0, h: 0 };
  }
}

async function webdavFileInfo(path: string): Promise<{ fileid: number; etag: string }> {
  const url = `/remote.php/dav/files/${encodeURIComponent(utils.uid ?? '')}${path
    .split('/')
    .map(encodeURIComponent)
    .join('/')}`;
  const body = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <oc:fileid/>
    <d:getetag/>
  </d:prop>
</d:propfind>`;
  const res = await axios.request({
    method: 'PROPFIND',
    url,
    data: body,
    headers: { Depth: '0', 'Content-Type': 'application/xml' },
  });
  const text = typeof res.data === 'string' ? res.data : new XMLSerializer().serializeToString(res.data);
  const doc = new DOMParser().parseFromString(text, 'application/xml');
  const fileid = parseInt(doc.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent ?? '0', 10);
  const etag = (doc.getElementsByTagNameNS('DAV:', 'getetag')[0]?.textContent ?? '').replace(/"/g, '');
  return { fileid, etag };
}

function onRect(drawn: Rect | null) {
  rect.value = drawn;
  if (drawn) {
    selectedFaceIds.value = [];
    saveError.value = '';
    focusName();
  }
}

/**
 * Selects a face, or with Ctrl+click adds it to the faces selected or
 * takes it out of them.
 */
function selectFace(faceId: number, additive = false) {
  // While saving, the rectangle has to stay, to try again if it fails.
  if (saving.value) return;
  if (!faces.value.some((f) => f.id === faceId)) return;
  rect.value = null;
  saveError.value = '';
  if (additive) {
    selectedFaceIds.value = selectedFaceIds.value.includes(faceId)
      ? selectedFaceIds.value.filter((id) => id !== faceId)
      : [...selectedFaceIds.value, faceId];
  } else {
    selectedFaceIds.value = [faceId];
  }
  if (selectedFaceIds.value.length === 1) {
    nextTick(() => panel.value?.focusName());
  }
}

/** A searched area was removed from the list: tell, and read the photo again. */
async function onRegionRemoved(message: string): Promise<void> {
  showNotice(message);
  highlightedRegionId.value = null;
  await loadFaces();
}

function clearSelection() {
  selectedFaceIds.value = [];
}

/** The panel of the selected faces changed them: tell, and read the faces again. */
async function onSelectionDone(message: string): Promise<void> {
  showNotice(message);
  selectedFaceIds.value = [];
  changed = true;
  await loadFaces();
}

/** The rectangle as the server takes it, measured against the original. */
function manualRect() {
  const drawn = rect.value!;
  return {
    fileId: fileId.value,
    x: drawn.x,
    y: drawn.y,
    width: drawn.w,
    height: drawn.h,
    imageWidth: imageNatW.value,
    imageHeight: imageNatH.value,
  };
}

function saveNamed(): Promise<void> {
  return canSaveNamed.value ? saveMarking(name.value) : Promise.resolve();
}

function saveUnnamed(): Promise<void> {
  return canSaveUnnamed.value ? saveMarking('') : Promise.resolve();
}

/**
 * Saves the rectangle as a face, and keeps the dialog open for the next
 * one. If saving fails, the rectangle stays, to try again.
 */
async function saveMarking(personName: string): Promise<void> {
  if (!rect.value || !fileId.value || !dimensionsKnown.value || saving.value) return;
  saving.value = true;
  saveError.value = '';
  try {
    await faceRecognitionAddManualFace({ ...manualRect(), personName });
    showNotice(
      personName
        ? t('memories', 'Person "{name}" tagged.', { name: personName })
        : t('memories', 'Saved. The face recognition will look for the person on its next run.'),
    );
    rect.value = null;
    rawInput.value = '';
    changed = true;
  } catch (e) {
    console.error(e);
    saveError.value = errorText(e, t('memories', 'Failed to save the manual face.'));
    return;
  } finally {
    saving.value = false;
  }
  await loadFaces();
}

async function saveRegion(): Promise<void> {
  if (!rect.value || !fileId.value || !dimensionsKnown.value || saving.value || !canSearchRegion.value) return;
  saving.value = true;
  saveError.value = '';
  try {
    await faceRecognitionAddManualRegion(manualRect());
    showNotice(t('memories', 'The area will be searched for faces on the next run of the face recognition.'));
    rect.value = null;
    rawInput.value = '';
  } catch (e) {
    console.error(e);
    saveError.value = errorText(e, t('memories', 'The area could not be queued for a search.'));
    return;
  } finally {
    saving.value = false;
  }
  await loadFaces();
}

defineExpose({ open, openForFile });
</script>

<style lang="scss" scoped>
.manual-face-add {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 320px;
}

.picker-step {
  padding: 16px 0;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: center;
}

.draw-step {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.hint {
  font-size: 0.9em;
  opacity: 0.8;
  margin: 0;
}

.stage-wrap {
  position: relative;
}

// Over the photo, so that it moves nothing while it comes and goes.
.saved-notice {
  position: absolute;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  max-width: calc(100% - 24px);
  padding: 8px 14px;
  border-radius: var(--border-radius-large, 10px);
  // Dark and see-through, like the labels of the faces: readable on any
  // photo, in the light and the dark theme alike.
  background: rgba(0, 0, 0, 0.78);
  color: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
  font-size: 0.95em;
  text-align: center;
  pointer-events: none;
  z-index: 2;
}

.saved-notice-enter-active,
.saved-notice-leave-active {
  transition: opacity 0.25s ease;
}

.saved-notice-enter-from,
.saved-notice-leave-to {
  opacity: 0;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  justify-content: center;
  font-size: 0.85em;
  opacity: 0.85;

  span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
  }

  .swatch {
    display: inline-block;
    // The border comes on top of the size; NcModal makes everything in it border-box.
    box-sizing: content-box;
    width: 16px;
    height: 10px;
    border: 2px solid #95a5a6;

    &.origin-auto {
      border-color: #2ecc71;
    }
    &.origin-manual {
      border-color: #f1c40f;
    }
    &.line-pending {
      border-style: dashed;
    }
    &.line-excluded {
      border-style: dotted;
      border-width: 3px;
    }
    &.ignored {
      border: 1px dotted #95a5a6;
      opacity: 0.6;
    }
  }
}

.fields {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 6px;

  .scope {
    margin: 0;
    font-size: 0.9em;
    padding: 6px 8px;
    border-left: 3px solid var(--color-primary-element);
    background: var(--color-background-hover);
  }
}
</style>
