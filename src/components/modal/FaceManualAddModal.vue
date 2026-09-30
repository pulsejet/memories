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
          <option v-for="n in knownNames" :key="n" :value="n" />
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

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';
import axios from '@nextcloud/axios';
import { getFilePickerBuilder } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard';
const NcTextField = defineAsyncComponent(() => import('@nextcloud/vue/components/NcTextField'));

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';
import FaceMarkingStage from './FaceMarkingStage.vue';
import FaceSelectionPanel from './FaceSelectionPanel.vue';
import FaceRegionList from './FaceRegionList.vue';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
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
  markingScope,
  regionScope,
  stageFaceOf,
  stageRegionOf,
  type Rect,
  type StageFace,
  type StageRegion,
} from './faceMarking';

/** How long a saved marking is announced over the photo */
const NOTICE_MS = 4000;

export default defineComponent({
  name: 'FaceManualAddModal',
  components: { NcButton, NcNoteCard, NcTextField, Modal, FaceMarkingStage, FaceSelectionPanel, FaceRegionList },

  mixins: [ModalMixin],

  emits: {
    /** Faces were added or moved while the dialog was open; sent when it closes */
    added: () => true,
  },

  data: () => ({
    fileId: 0,
    imageSrc: '',
    imageNatW: 0,
    imageNatH: 0,
    faces: [] as IFaceRectForFile[],
    regions: null as IManualRegion[] | null,
    limits: null as IFaceLimits | null,
    legacy: false,
    loadError: '',
    rect: null as Rect | null,
    rawInput: '',
    saving: false,
    saveError: '',
    /** The faces selected, in the order they were picked; several with Ctrl+click */
    selectedFaceIds: [] as number[],
    /** The searched area the pointer is on in the list, shown on the photo */
    highlightedRegionId: null as number | null,
    knownNames: [] as string[],
    navigationError: false,
    /** What was just saved, shown over the photo for a moment */
    notice: '',
    noticeTimer: 0,
    /**
     * Whether anything was saved. The sidebar and the timeline are told when
     * the dialog closes, not on each save: the sidebar reloads by dropping
     * its content, and this dialog with it.
     */
    changed: false,
  }),

  computed: {
    /** The size of the original photo, which every marking is measured against. */
    dimensionsKnown(): boolean {
      return this.imageNatW > 0 && this.imageNatH > 0;
    },

    canDraw(): boolean {
      return this.dimensionsKnown && !this.saving;
    },

    name(): string {
      return this.rawInput.trim();
    },

    canSaveNamed(): boolean {
      return !!this.rect && !!this.name && !this.saving && this.dimensionsKnown;
    },

    canSaveUnnamed(): boolean {
      return !this.legacy;
    },

    canSearchRegion(): boolean {
      return !this.legacy && this.regions !== null;
    },

    hint(): string {
      if (!this.dimensionsKnown) return '';
      if (this.selectedFaces.length > 1) {
        return t(
          'memories',
          'Ctrl+click adds a face or takes it out again. Draw a new rectangle to mark another face.',
        );
      }
      if (this.selectedFaces.length === 1) {
        return t(
          'memories',
          'Draw a new rectangle to mark another face. Ctrl+click selects several faces, to ignore or delete them together.',
        );
      }
      if (this.rect) {
        return t('memories', 'Enter a name, or save without one. You can redraw by dragging again.');
      }
      return t(
        'memories',
        'Drag on the photo to mark a face, or an area with several faces to search again. Zoom with the mouse wheel or two fingers, and move with the middle mouse button or two fingers. Click a face to see its state or rename it, Ctrl+click to select several.',
      );
    },

    stageFaces(): StageFace[] {
      if (!this.dimensionsKnown) return [];
      return this.faces.map((face) =>
        stageFaceOf(face, this.imageNatW, this.imageNatH, this.limits, this.selectedFaceIds.includes(face.id)),
      );
    },

    stageRegions(): StageRegion[] {
      if (!this.dimensionsKnown || !this.regions) return [];
      return this.regions.map((region) =>
        stageRegionOf(region, this.imageNatW, this.imageNatH, region.id === this.highlightedRegionId),
      );
    },

    markingScope(): string {
      return markingScope(this.name, this.knownNames);
    },

    regionScope(): string {
      return regionScope();
    },

    /** The faces selected that are on the photo, in the order they were picked. */
    selectedFaces(): IFaceRectForFile[] {
      return this.selectedFaceIds
        .map((id) => this.faces.find((face) => face.id === id))
        .filter((face): face is IFaceRectForFile => !!face);
    },
  },

  methods: {
    open() {
      this.resetAll();
      this.changed = false;
      this.show = true;
      this.loadKnownNames();
    },

    async openForFile(info: { fileid: number; etag?: string; w?: number; h?: number }) {
      this.resetAll();
      this.changed = false;
      this.show = true;
      this.loadKnownNames();
      if (!info?.fileid) return;

      this.fileId = info.fileid;
      // Only the size of the original is any good: a marking is measured
      // against it, and the preview is scaled down.
      this.imageNatW = info.w ?? 0;
      this.imageNatH = info.h ?? 0;
      this.imageSrc = this.previewOf(this.fileId, info.etag);
      await this.loadFaces();
    },

    cleanup() {
      this.show = false;
      this.resetAll();
      if (this.changed) {
        this.changed = false;
        this.$emit('added');
      }
    },

    /** Shows what was just saved over the photo, for a few seconds. */
    showNotice(text: string) {
      window.clearTimeout(this.noticeTimer);
      this.notice = text;
      this.noticeTimer = window.setTimeout(() => (this.notice = ''), NOTICE_MS);
    },

    /** Puts the cursor in the name field of a new marking, without scrolling to it. */
    focusName() {
      this.$nextTick(() => focusWithoutScrolling(() => inputOf(this.$refs.nameField)));
    },

    previewOf(fileId: number, etag?: string): string {
      return API.Q(API.IMAGE_PREVIEW(fileId), { c: etag, x: 2048, y: 2048, a: '1' });
    },

    /**
     * Load the faces of the photo, with their state, and the regions queued
     * on it. A failure is shown here, and leaves the rest of the app alone.
     */
    async loadFaces(): Promise<void> {
      const fileId = this.fileId;
      if (!fileId) return;
      this.loadError = '';
      try {
        const result = await faceRecognitionGetFacesForFile(fileId);
        // The dialog moved on to another photo, or was closed, meanwhile.
        if (fileId !== this.fileId) return;
        this.faces = result.faces;
        this.regions = result.regions;
        this.limits = result.limits;
        this.legacy = result.legacy;
        this.selectedFaceIds = this.selectedFaceIds.filter((id) => this.faces.some((face) => face.id === id));
      } catch (e) {
        console.error(e);
        if (fileId !== this.fileId) return;
        this.loadError = errorText(e, t('memories', 'The faces of this photo could not be loaded.'));
      }
    },

    /**
     * Load the names of already-known persons so the name fields can offer
     * autocompletion — mirrors how naming an unknown cluster suggests existing names.
     * Failure is non-fatal: it just means no suggestions are shown.
     */
    async loadKnownNames(): Promise<void> {
      try {
        const faces = await getFaceList('facerecognition');
        const names = faces
          .map((f) => f.name)
          // Keep only real names; unnamed clusters expose a numeric id as their name.
          .filter((n): n is string => !!n && Number.isNaN(Number(n)));
        this.knownNames = Array.from(new Set(names)).sort((a, b) => a.localeCompare(b));
      } catch (e) {
        console.error(e);
        this.knownNames = [];
      }
    },

    resetAll() {
      this.resetFile();
      this.knownNames = [];
      this.navigationError = false;
    },

    resetFile() {
      this.fileId = 0;
      this.imageSrc = '';
      this.imageNatW = 0;
      this.imageNatH = 0;
      this.faces = [];
      this.regions = null;
      this.limits = null;
      this.legacy = false;
      this.loadError = '';
      this.rect = null;
      this.rawInput = '';
      this.saving = false;
      this.saveError = '';
      this.selectedFaceIds = [];
      this.highlightedRegionId = null;
      window.clearTimeout(this.noticeTimer);
      this.notice = '';
    },

    async pickFile(): Promise<void> {
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
        path = (await picker.pick()) as string;
      } catch (e) {
        return; // user cancelled
      }
      if (!path) return;

      await this.loadPhotoByPath(path);
    },

    async loadPhotoByPath(path: string): Promise<void> {
      try {
        // Memories' IMAGE_INFO requires a fileid, so the file is looked up via WebDAV.
        const props = await this.webdavFileInfo(path);
        this.fileId = props.fileid;
        this.imageNatW = props.w;
        this.imageNatH = props.h;
        this.imageSrc = this.previewOf(this.fileId, props.etag);
      } catch (e) {
        console.error(e);
        this.resetFile();
        this.loadError = t('memories', 'Failed to load the selected photo.');
        return;
      }
      await this.loadFaces();
    },

    async webdavFileInfo(path: string): Promise<{ fileid: number; etag: string; w: number; h: number }> {
      const url = `/remote.php/dav/files/${encodeURIComponent((window as any).OC?.getCurrentUser?.().uid || '')}${path
        .split('/')
        .map(encodeURIComponent)
        .join('/')}`;
      const body = `<?xml version="1.0"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">
  <d:prop>
    <oc:fileid/>
    <d:getetag/>
    <nc:metadata-photos-size/>
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
      const fileid = parseInt(
        doc.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent ?? '0',
        10,
      );
      const etag = (doc.getElementsByTagNameNS('DAV:', 'getetag')[0]?.textContent ?? '').replace(/"/g, '');
      const sizeEl =
        doc.getElementsByTagNameNS('http://nextcloud.org/ns', 'metadata-photos-size')[0]?.textContent ?? '';
      // Without the size of the original the photo is shown, but nothing can
      // be marked on it: the size of the preview would put it elsewhere.
      let w = 0,
        h = 0;
      const m = sizeEl.match(/(\d+)[^\d]+(\d+)/);
      if (m) {
        w = parseInt(m[1], 10);
        h = parseInt(m[2], 10);
      }
      return { fileid, etag, w, h };
    },

    onRect(rect: Rect | null) {
      this.rect = rect;
      if (rect) {
        this.selectedFaceIds = [];
        this.saveError = '';
        this.focusName();
      }
    },

    /**
     * Selects a face, or with Ctrl+click adds it to the faces selected or
     * takes it out of them.
     */
    selectFace(faceId: number, additive = false) {
      // While saving, the rectangle has to stay, to try again if it fails.
      if (this.saving) return;
      if (!this.faces.some((f) => f.id === faceId)) return;
      this.rect = null;
      this.saveError = '';
      if (additive) {
        this.selectedFaceIds = this.selectedFaceIds.includes(faceId)
          ? this.selectedFaceIds.filter((id) => id !== faceId)
          : [...this.selectedFaceIds, faceId];
      } else {
        this.selectedFaceIds = [faceId];
      }
      if (this.selectedFaceIds.length === 1) {
        this.$nextTick(() => (this.$refs.panel as InstanceType<typeof FaceSelectionPanel> | undefined)?.focusName());
      }
    },

    /** A searched area was removed from the list: tell, and read the photo again. */
    async onRegionRemoved(message: string): Promise<void> {
      this.showNotice(message);
      this.highlightedRegionId = null;
      await this.loadFaces();
    },

    clearSelection() {
      this.selectedFaceIds = [];
    },

    /** The panel of the selected faces changed them: tell, and read the faces again. */
    async onSelectionDone(message: string): Promise<void> {
      this.showNotice(message);
      this.selectedFaceIds = [];
      this.changed = true;
      await this.loadFaces();
    },

    /** The rectangle as the server takes it, measured against the original. */
    manualRect() {
      const rect = this.rect!;
      return {
        fileId: this.fileId,
        x: rect.x,
        y: rect.y,
        width: rect.w,
        height: rect.h,
        imageWidth: this.imageNatW,
        imageHeight: this.imageNatH,
      };
    },

    saveNamed(): Promise<void> {
      return this.canSaveNamed ? this.saveMarking(this.name) : Promise.resolve();
    },

    saveUnnamed(): Promise<void> {
      return this.canSaveUnnamed ? this.saveMarking('') : Promise.resolve();
    },

    /**
     * Saves the rectangle as a face, and keeps the dialog open for the next
     * one. If saving fails, the rectangle stays, to try again.
     */
    async saveMarking(personName: string): Promise<void> {
      if (!this.rect || !this.fileId || !this.dimensionsKnown || this.saving) return;
      this.saving = true;
      this.saveError = '';
      try {
        await faceRecognitionAddManualFace({ ...this.manualRect(), personName });
        this.showNotice(
          personName
            ? t('memories', 'Person "{name}" tagged.', { name: personName })
            : t('memories', 'Saved. The face recognition will look for the person on its next run.'),
        );
        this.rect = null;
        this.rawInput = '';
        this.changed = true;
      } catch (e) {
        console.error(e);
        this.saveError = errorText(e, t('memories', 'Failed to save the manual face.'));
        return;
      } finally {
        this.saving = false;
      }
      await this.loadFaces();
    },

    async saveRegion(): Promise<void> {
      if (!this.rect || !this.fileId || !this.dimensionsKnown || this.saving || !this.canSearchRegion) return;
      this.saving = true;
      this.saveError = '';
      try {
        await faceRecognitionAddManualRegion(this.manualRect());
        this.showNotice(t('memories', 'The area will be searched for faces on the next run of the face recognition.'));
        this.rect = null;
        this.rawInput = '';
      } catch (e) {
        console.error(e);
        this.saveError = errorText(e, t('memories', 'The area could not be queued for a search.'));
        return;
      } finally {
        this.saving = false;
      }
      await this.loadFaces();
    },
  },
});
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
