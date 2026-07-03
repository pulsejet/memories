<template>
  <Modal ref="modal" @close="cleanup" v-if="show" size="large">
    <template #title>
      {{ t('memories', 'Add person') }}
    </template>

    <div class="manual-face-add">
      <!-- Step 1: pick a file -->
      <div v-if="!fileId" class="picker-step">
        <p>{{ t('memories', 'Choose a photo containing the person you want to tag.') }}</p>
        <NcButton type="primary" @click="pickFile">
          {{ t('memories', 'Choose photo') }}
        </NcButton>
      </div>

      <!-- Step 2: draw rectangle -->
      <div v-else class="draw-step">
        <!-- Suggestions of already-known person names for the name fields below -->
        <datalist id="memories-manual-face-names">
          <option v-for="n in knownNames" :key="n" :value="n" />
        </datalist>

        <p v-if="!rect" class="hint">
          {{ t('memories', 'Drag on the photo to mark the face. Existing detections are shown in green.') }}
        </p>
        <p v-else class="hint">
          {{ t('memories', 'Enter a name below. You can redraw by dragging again.') }}
        </p>

        <div
          ref="stage"
          class="stage"
          @mousedown="onDown"
          @mousemove="onMove"
          @mouseup="onUp"
          @mouseleave="onUp"
          @touchstart.prevent="onTouchDown"
          @touchmove.prevent="onTouchMove"
          @touchend.prevent="onUp"
        >
          <img
            ref="image"
            class="photo"
            :src="imageSrc"
            @load="onImageLoad"
            draggable="false"
          />

          <!-- Existing detected/manual faces -->
          <div
            v-for="f in existingFacesDisplay"
            :key="f.id"
            class="face-box existing"
            :class="{ manual: f.isManual, editing: editingFace && editingFace.id === f.id }"
            :style="f.style"
            :title="f.personName || t('memories', 'Unnamed person')"
            @mousedown.stop
            @touchstart.stop
            @click.stop="onExistingClick(f.raw)"
          >
            <span class="label">{{ f.personName || '?' }}</span>
          </div>

          <!-- New rectangle being drawn / drawn -->
          <div v-if="rectDisplay" class="face-box drawing" :style="rectDisplay" />
        </div>

        <div v-if="rect && !editingFace" class="fields">
          <NcTextField
            class="field"
            :autofocus="true"
            :value.sync="rawInput"
            :label="t('memories', 'Name')"
            :label-visible="false"
            :placeholder="t('memories', 'Name')"
            list="memories-manual-face-names"
            @keypress.enter="save()"
          />
          <label class="checkbox-row">
            <input type="checkbox" v-model="useForClustering" />
            <span>{{ t('memories', 'Also use this face for automatic recognition') }}</span>
          </label>
        </div>

        <div v-if="editingFace" class="fields">
          <p class="hint">
            {{ t('memories', 'Reassign this face to a different person (only on this photo).') }}
          </p>
          <NcTextField
            class="field"
            :autofocus="true"
            :value.sync="editName"
            :label="t('memories', 'Name')"
            :label-visible="false"
            :placeholder="t('memories', 'Name')"
            list="memories-manual-face-names"
            @keypress.enter="saveEdit()"
          />
        </div>
      </div>
    </div>

    <template #buttons>
      <NcButton v-if="fileId && !rect && !editingFace" @click="resetFile">
        {{ t('memories', 'Choose different photo') }}
      </NcButton>
      <NcButton v-if="editingFace" @click="cancelEdit">
        {{ t('memories', 'Cancel') }}
      </NcButton>
      <NcButton
        v-if="editingFace"
        class="button"
        type="primary"
        :disabled="!canSaveEdit"
        @click="saveEdit"
      >
        {{ t('memories', 'Save') }}
      </NcButton>
      <NcButton v-if="rect && !editingFace" class="button" type="primary" :disabled="!canSave || saving" @click="save">
        {{ t('memories', 'Save') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';
import axios from '@nextcloud/axios';
import { showError, showInfo, showSuccess, getFilePickerBuilder } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcTextField = () => import('@nextcloud/vue/dist/Components/NcTextField.js');

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import {
  faceRecognitionAddManualFace,
  faceRecognitionGetFacesForFile,
  faceRecognitionReassignFace,
  getFaceList,
  type IFaceRectForFile,
} from '@services/dav/face';

type Rect = { x: number; y: number; w: number; h: number }; // fractions 0..1 of original image

function toCss(r: Rect): Record<string, string> {
  return {
    left: `${r.x * 100}%`,
    top: `${r.y * 100}%`,
    width: `${r.w * 100}%`,
    height: `${r.h * 100}%`,
  };
}

function rectFromPoints(a: { x: number; y: number }, b: { x: number; y: number }): Rect {
  return {
    x: Math.min(a.x, b.x),
    y: Math.min(a.y, b.y),
    w: Math.abs(a.x - b.x),
    h: Math.abs(a.y - b.y),
  };
}

type FaceDisplay = {
  id: number;
  personName: string | null;
  isManual: boolean;
  style: Record<string, string>;
  raw: IFaceRectForFile;
};

export default defineComponent({
  name: 'FaceManualAddModal',
  components: { NcButton, NcTextField, Modal },

  mixins: [ModalMixin],

  emits: {
    added: (_name: string) => true,
  },

  data: () => ({
    fileId: 0,
    imageSrc: '',
    imageNatW: 0,
    imageNatH: 0,
    existingFaces: [] as IFaceRectForFile[],
    rect: null as Rect | null,
    dragStart: null as { x: number; y: number } | null,
    rawInput: '',
    useForClustering: false,
    saving: false,
    editingFace: null as IFaceRectForFile | null,
    editName: '',
    knownNames: [] as string[],
  }),

  computed: {
    canSave(): boolean {
      return !!this.rect && !!this.rawInput.trim() && !this.saving;
    },

    rectDisplay(): Record<string, string> | null {
      if (!this.rect) return null;
      return toCss(this.rect);
    },

    existingFacesDisplay(): FaceDisplay[] {
      if (!this.imageNatW || !this.imageNatH) return [];
      return this.existingFaces.map((f) => ({
        id: f.id,
        personName: f.personName,
        isManual: f.isManual,
        style: toCss({
          x: f.x / this.imageNatW,
          y: f.y / this.imageNatH,
          w: f.width / this.imageNatW,
          h: f.height / this.imageNatH,
        }),
        raw: f,
      }));
    },

    canSaveEdit(): boolean {
      return !!this.editingFace && !!this.editName.trim() && !this.saving;
    },
  },

  methods: {
    open() {
      this.resetAll();
      this.show = true;
      this.loadKnownNames();
    },

    async openForFile(info: { fileid: number; etag?: string; w?: number; h?: number }) {
      this.resetAll();
      this.show = true;
      this.loadKnownNames();
      if (!info?.fileid) return;
      try {
        this.fileId = info.fileid;
        this.imageNatW = info.w ?? 0;
        this.imageNatH = info.h ?? 0;
        this.imageSrc = API.Q(API.IMAGE_PREVIEW(this.fileId), {
          c: info.etag,
          x: 2048,
          y: 2048,
          a: '1',
        });
        this.existingFaces = await faceRecognitionGetFacesForFile(this.fileId);
      } catch (e) {
        console.error(e);
        showError(t('memories', 'Failed to load the selected photo.'));
        this.resetFile();
      }
    },

    cleanup() {
      this.show = false;
      this.resetAll();
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
      this.fileId = 0;
      this.imageSrc = '';
      this.imageNatW = 0;
      this.imageNatH = 0;
      this.existingFaces = [];
      this.rect = null;
      this.dragStart = null;
      this.rawInput = '';
      this.useForClustering = false;
      this.saving = false;
      this.editingFace = null;
      this.editName = '';
    },

    resetFile() {
      this.fileId = 0;
      this.imageSrc = '';
      this.rect = null;
      this.existingFaces = [];
      this.imageNatW = this.imageNatH = 0;
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
        // Fetch fileinfo (fileid, w, h, etag) via Memories image info by path.
        // Memories' IMAGE_INFO requires fileid; so we first look the file up via WebDAV.
        const props = await this.webdavFileInfo(path);
        this.fileId = props.fileid;
        this.imageNatW = props.w ?? 0;
        this.imageNatH = props.h ?? 0;

        // Preview source large enough to draw comfortably.
        this.imageSrc = API.Q(API.IMAGE_PREVIEW(this.fileId), {
          c: props.etag,
          x: 2048,
          y: 2048,
          a: '1',
        });

        // Fetch existing faces for this file.
        this.existingFaces = await faceRecognitionGetFacesForFile(this.fileId);
      } catch (e) {
        console.error(e);
        showError(t('memories', 'Failed to load the selected photo.'));
        this.resetFile();
      }
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
      const fileid = parseInt(doc.getElementsByTagNameNS('http://owncloud.org/ns', 'fileid')[0]?.textContent ?? '0', 10);
      const etag = (doc.getElementsByTagNameNS('DAV:', 'getetag')[0]?.textContent ?? '').replace(/"/g, '');
      const sizeEl = doc.getElementsByTagNameNS('http://nextcloud.org/ns', 'metadata-photos-size')[0]?.textContent ?? '';
      let w = 0,
        h = 0;
      const m = sizeEl.match(/(\d+)[^\d]+(\d+)/);
      if (m) {
        w = parseInt(m[1], 10);
        h = parseInt(m[2], 10);
      }
      return { fileid, etag, w, h };
    },

    onImageLoad() {
      const img = this.$refs.image as HTMLImageElement;
      // Preview is scaled down — we still need original dims. If PROPFIND didn't give them,
      // assume the preview ratio matches original and use preview natural size as a fallback.
      if (!this.imageNatW || !this.imageNatH) {
        this.imageNatW = img.naturalWidth;
        this.imageNatH = img.naturalHeight;
      }
    },

    stagePoint(ev: MouseEvent | Touch): { x: number; y: number } | null {
      const img = this.$refs.image as HTMLImageElement | undefined;
      if (!img) return null;
      const rect = img.getBoundingClientRect();
      const x = (ev.clientX - rect.left) / rect.width;
      const y = (ev.clientY - rect.top) / rect.height;
      return { x: Math.max(0, Math.min(1, x)), y: Math.max(0, Math.min(1, y)) };
    },

    onDown(ev: MouseEvent) {
      if (this.editingFace) return;
      const p = this.stagePoint(ev);
      if (!p) return;
      this.dragStart = p;
      this.rect = { x: p.x, y: p.y, w: 0, h: 0 };
    },
    onMove(ev: MouseEvent) {
      if (!this.dragStart) return;
      const p = this.stagePoint(ev);
      if (!p) return;
      this.rect = rectFromPoints(this.dragStart, p);
    },
    onTouchDown(ev: TouchEvent) {
      if (this.editingFace) return;
      const t0 = ev.touches[0];
      if (!t0) return;
      const p = this.stagePoint(t0);
      if (!p) return;
      this.dragStart = p;
      this.rect = { x: p.x, y: p.y, w: 0, h: 0 };
    },
    onTouchMove(ev: TouchEvent) {
      if (!this.dragStart) return;
      const t0 = ev.touches[0];
      if (!t0) return;
      const p = this.stagePoint(t0);
      if (!p) return;
      this.rect = rectFromPoints(this.dragStart, p);
    },
    onUp() {
      if (!this.rect) {
        this.dragStart = null;
        return;
      }
      // Discard micro-clicks.
      if (this.rect.w < 0.01 || this.rect.h < 0.01) this.rect = null;
      this.dragStart = null;
    },

    onExistingClick(f: IFaceRectForFile) {
      this.rect = null;
      this.dragStart = null;
      this.editingFace = f;
      this.editName = f.personName ?? '';
    },

    cancelEdit() {
      this.editingFace = null;
      this.editName = '';
    },

    async saveEdit(): Promise<void> {
      if (!this.canSaveEdit || !this.editingFace) return;
      const target = this.editName.trim();
      const faceId = this.editingFace.id;
      this.saving = true;
      try {
        await faceRecognitionReassignFace(faceId, target);
        showSuccess(t('memories', 'Face reassigned to "{name}".', { name: target }));
        this.editingFace = null;
        this.editName = '';
        if (this.fileId) {
          this.existingFaces = await faceRecognitionGetFacesForFile(this.fileId);
        }
        this.$emit('added', target);
      } catch (e) {
        console.error(e);
        showError(t('memories', 'Failed to reassign the face.'));
      } finally {
        this.saving = false;
      }
    },

    async save(): Promise<void> {
      if (!this.canSave || !this.rect || !this.fileId) return;
      if (!this.imageNatW || !this.imageNatH) {
        showError(t('memories', 'Could not determine image dimensions.'));
        return;
      }
      this.saving = true;
      try {
        const result = await faceRecognitionAddManualFace({
          fileId: this.fileId,
          personName: this.rawInput.trim(),
          x: this.rect.x,
          y: this.rect.y,
          width: this.rect.w,
          height: this.rect.h,
          imageWidth: this.imageNatW,
          imageHeight: this.imageNatH,
          useForClustering: this.useForClustering,
        });
        showSuccess(t('memories', 'Person "{name}" tagged.', { name: this.rawInput.trim() }));
        if (this.useForClustering && result.clusteringQueued) {
          showInfo(
            t(
              'memories',
              'Saved for "{name}". The next background scan will look for a face in the marked area and, if one is found, use it for automatic recognition.',
              { name: this.rawInput.trim() },
            ),
          );
        }
        this.$emit('added', this.rawInput.trim());
        await this.close();
      } catch (e) {
        console.error(e);
        showError(t('memories', 'Failed to save the manual face.'));
      } finally {
        this.saving = false;
      }
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

.stage {
  position: relative;
  user-select: none;
  display: inline-block;
  max-width: 100%;
  align-self: center;
  cursor: crosshair;
  background: var(--color-background-dark);

  .photo {
    display: block;
    max-width: 100%;
    max-height: 70vh;
    height: auto;
    pointer-events: none;
  }

  .face-box {
    position: absolute;
    box-sizing: border-box;
    pointer-events: none;

    &.existing {
      border: 2px solid #2ecc71;
      box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.4);
      pointer-events: auto;
      cursor: pointer;

      &.manual {
        border-color: #f1c40f;
      }

      &.editing {
        border-color: #e67e22;
        box-shadow: 0 0 0 2px rgba(230, 126, 34, 0.5);
      }

      .label {
        position: absolute;
        bottom: -1.4em;
        left: 0;
        font-size: 11px;
        background: rgba(0, 0, 0, 0.65);
        color: #fff;
        padding: 1px 4px;
        border-radius: 2px;
        white-space: nowrap;
      }
    }

    &.drawing {
      border: 2px dashed #3498db;
      background: rgba(52, 152, 219, 0.15);
    }
  }
}

.fields {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 6px;
}

.checkbox-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.9em;
  cursor: pointer;
}
</style>
