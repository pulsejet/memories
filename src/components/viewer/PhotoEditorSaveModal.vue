<template>
  <Modal ref="modal" size="small" :can-close="!saving" @close="onCancel">
    <template #title>
      {{ t('memories', 'Save image') }}
    </template>

    <div class="me-save-form">
      <NcTextField
        v-model="name"
        :label="t('memories', 'File name')"
        :label-visible="true"
        :disabled="saving || !saveAsCopy"
        :placeholder="t('memories', 'File name')"
        @keydown.enter="onSave"
      />

      <label class="me-save-label" for="me-save-format">
        {{ t('memories', 'Format') }}
      </label>
      <select id="me-save-format" v-model="format" class="me-save-select" :disabled="saving">
        <option value="jpg">JPEG</option>
        <option value="png">PNG</option>
        <option value="avif">AVIF</option>
      </select>

      <div class="me-save-quality">
        <div class="me-save-label">{{ t('memories', 'Quality') }}</div>
        <NcCheckboxRadioSwitch
          v-model="quality"
          :value="90"
          name="me-save-quality"
          type="radio"
          :disabled="saving || format === 'png'"
        >
          {{ t('memories', 'Best quality') }} (90)
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch
          v-model="quality"
          :value="85"
          name="me-save-quality"
          type="radio"
          :disabled="saving || format === 'png'"
        >
          {{ t('memories', 'Balanced') }} (85)
        </NcCheckboxRadioSwitch>
        <NcCheckboxRadioSwitch
          v-model="quality"
          :value="75"
          name="me-save-quality"
          type="radio"
          :disabled="saving || format === 'png'"
        >
          {{ t('memories', 'Space saving') }} (75)
        </NcCheckboxRadioSwitch>
      </div>

      <NcCheckboxRadioSwitch v-model="saveAsCopy" type="checkbox" :disabled="saving || forceCopy">
        {{ t('memories', 'Save as a copy') }}
      </NcCheckboxRadioSwitch>

      <div class="me-save-hint">
        <template v-if="isLivePhoto">
          {{ t('memories', 'Live photo: a copy will always be created.') }}
        </template>
        <template v-else-if="willCopy">
          {{ t('memories', 'A new file will be created.') }}
        </template>
        <template v-else>
          {{ t('memories', 'The original file will be overwritten.') }}
        </template>
      </div>

      <div v-if="saving" class="me-save-progress">
        <NcLoadingIcon :size="20" />
        <span>{{ t('memories', 'Saving …') }}</span>
      </div>
      <div v-if="saveError" class="me-save-error">
        {{ saveError }}
      </div>
    </div>

    <template #buttons>
      <NcButton :disabled="saving" @click="onCancel">
        {{ t('memories', 'Cancel') }}
      </NcButton>
      <NcButton variant="primary" :disabled="saving || !canSave" @click="onSave">
        {{ t('memories', 'Save') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon';
import NcTextField from '@nextcloud/vue/components/NcTextField';
import axios from '@nextcloud/axios';

import Modal from '@components/modal/Modal.vue';
import { stateToSaveEdits } from '@components/viewer/editor-controls/stateToEdits';

import { API } from '@services/API';
import { t } from '@services/l10n';
import * as utils from '@services/utils/common';
import { showError, showSuccess } from '@services/utils/dialog';

import type { EditorState } from '@components/viewer/editor-controls/types';
import type { IImageInfo, IPhoto } from '@typings';

const props = defineProps<{
  photo: IPhoto;
  state: EditorState;
  imageWidth: number;
  imageHeight: number;
  isLivePhoto: boolean;
}>();

const emit = defineEmits<{
  close: [];
  saved: [info: IImageInfo, isCopy: boolean];
}>();

type SaveFormat = 'jpg' | 'png' | 'avif';

const EXT_MAP: Record<SaveFormat, string> = {
  jpg: '.jpg',
  png: '.png',
  avif: '.avif',
};

/** Format implied by the basename, or null when it matches none we offer. */
function matchFormat(basename: string): SaveFormat | null {
  const lower = (basename || '').toLowerCase();
  if (lower.endsWith('.jpg') || lower.endsWith('.jpeg')) return 'jpg';
  if (lower.endsWith('.png')) return 'png';
  if (lower.endsWith('.avif')) return 'avif';
  return null;
}

/** Whether the name already carries the format's extension. */
function hasExt(name: string, format: SaveFormat): boolean {
  const lower = (name || '').toLowerCase();
  if (format === 'jpg') return lower.endsWith('.jpg') || lower.endsWith('.jpeg');
  return lower.endsWith(EXT_MAP[format]);
}

function suggestCopyName(original: string, format: SaveFormat): string {
  const dot = original.lastIndexOf('.');
  const stem = dot > 0 ? original.slice(0, dot) : original;
  const ext = dot > 0 ? original.slice(dot) : EXT_MAP[format];
  return `${stem} (${t('memories', 'edited')})${ext}`;
}

const originalName = computed(() => props.photo.basename || '');
const originalMatched = computed<SaveFormat | null>(() => matchFormat(originalName.value));

const name = ref(originalName.value);
const format = ref<SaveFormat>(originalMatched.value ?? 'jpg');
/** Output quality for lossy formats (JPEG/AVIF); PNG is lossless. */
const quality = ref(90);
// Unmatched basenames can never overwrite: start ticked (watch keeps it so).
const saveAsCopy = ref(props.isLivePhoto || originalMatched.value === null);
const saving = ref(false);
const saveError = ref('');
/** Format whose extension we last suffixed onto the name (for de-stacking). */
const suffixed = ref<SaveFormat | null>(null);

// No appended extension yet when the basename implies no offered format
// (e.g. webp/heic): suffix the preselected JPEG extension right away.
if (!hasExt(name.value, format.value)) {
  name.value = `${name.value}${EXT_MAP[format.value]}`;
  suffixed.value = format.value;
}

// Anything but the basename's own format must not overwrite the original:
// tick the copy box and lock it. This also covers unmatched basenames,
// where the preselected JPEG never equals the (null) match.
const forceCopy = computed(() => format.value !== originalMatched.value);

watch(forceCopy, (forced) => {
  if (forced) saveAsCopy.value = true;
});

// When the user opts into a copy but the name still matches the original,
// suggest a distinct name so the backend "target exists" check does not fire.
watch(saveAsCopy, (copy) => {
  if (copy && !props.isLivePhoto && name.value === originalName.value) {
    name.value = suggestCopyName(originalName.value, format.value);
  }
});

// A newly selected format that is not the name's current one is suffixed,
// never substituted. A previously suffixed extension is dropped first so
// repeated switches (JPEG -> PNG -> AVIF) don't stack onto the name.
watch(format, (fmt) => {
  let base = name.value || originalName.value;
  if (suffixed.value && suffixed.value !== fmt) {
    const old = EXT_MAP[suffixed.value];
    if (base.toLowerCase().endsWith(old)) {
      base = base.slice(0, -old.length);
      name.value = base;
    }
    suffixed.value = null;
  }
  if (!hasExt(base, fmt)) {
    name.value = `${base}${EXT_MAP[fmt]}`;
    suffixed.value = fmt;
  }
});

const finalName = computed(() => {
  if (!saveAsCopy.value) return originalName.value;
  const current = name.value || originalName.value;
  return hasExt(current, format.value) ? current : `${current}${EXT_MAP[format.value]}`;
});

const willCopy = computed(() => props.isLivePhoto || saveAsCopy.value || finalName.value !== originalName.value);

const canSave = computed(() => {
  if (!finalName.value.trim()) return false;
  // Copies with a colliding name are auto-renamed backend-side.
  return true;
});

function onCancel() {
  if (saving.value) return;
  emit('close');
}

async function onSave() {
  if (saving.value || !canSave.value) return;
  saving.value = true;
  saveError.value = '';
  try {
    const edits = stateToSaveEdits(props.state, props.imageWidth, props.imageHeight);
    const res = await axios.put<IImageInfo>(API.IMAGE_EDIT(props.photo.fileid), {
      name: finalName.value,
      extension: format.value,
      edits,
      quality: quality.value,
      copy: willCopy.value,
    });
    const info = res.data;
    const isCopy = info.fileid !== props.photo.fileid;
    if (!isCopy) {
      utils.updatePhotoFromImageInfo(props.photo, info);
    }
    utils.bus.emit(isCopy ? 'files:file:created' : 'files:file:updated', { fileid: info.fileid });
    emit('saved', info, isCopy);
  } catch (e: any) {
    const msg = e?.response?.data?.message ?? e?.message ?? t('memories', 'Unknown error while saving image');
    saveError.value = t('memories', 'Error saving image: {error}', { error: msg });
    showError(saveError.value);
    console.error(e);
  } finally {
    saving.value = false;
  }
}
</script>

<style lang="scss" scoped>
.me-save-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 6px;
}

.me-save-label {
  font-size: 0.85em;
  font-weight: 500;
  margin-bottom: -6px;
}

.me-save-quality .me-save-label {
  margin-bottom: 4px;
}

.me-save-select {
  height: 34px;
  border-radius: 6px;
  border: 1px solid var(--color-border);
  background-color: var(--color-main-background);
  color: var(--color-main-text);
  padding: 0 8px;
}

.me-save-hint {
  font-size: 0.85em;
  opacity: 0.75;
}

.me-save-progress {
  display: flex;
  align-items: center;
  gap: 8px;
}

.me-save-error {
  color: var(--color-error);
  font-size: 0.85em;
  word-break: break-word;
}
</style>
