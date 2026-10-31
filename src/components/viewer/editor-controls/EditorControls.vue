<template>
  <div ref="rootEl" class="me-root" :style="accentStyle">
    <div class="me-workspace">
      <div class="me-side">
        <EditorCropStage
          ref="cropStage"
          :state="state"
          :section="section"
          :image-width="imageWidth"
          :image-height="imageHeight"
        >
          <slot name="preview" />
        </EditorCropStage>

        <EditorFloatActions :disabled="!hasEdits" @close="emit('back')" @save="onSave" />
        <EditorMobileBar
          :state="state"
          :defaults="defaults"
          :section="section"
          :mobile-key="mobileKey"
          @update:section="setSection"
          @update:mobile-key="(k) => (mobileKey = k === mobileKey ? null : k)"
          @update:param="setParam"
          @reset:param="resetParam"
          @update:preset="(id) => (state.preset = id)"
          @update:ratio="setRatio"
          @rotate-left="rotateBy(-90)"
          @rotate-right="rotateBy(90)"
          @flip-h="toggleFlipH"
          @flip-v="toggleFlipV"
        >
          <template #filter-thumb="{ filter }">
            <slot name="filter-thumb" :filter="filter" />
          </template>
        </EditorMobileBar>
      </div>

      <EditorSidebar
        :state="state"
        :defaults="defaults"
        :section="section"
        :image-width="imageWidth"
        :image-height="imageHeight"
        @update:section="setSection"
        @update:param="setParam"
        @reset:param="resetParam"
        @update:preset="(id) => (state.preset = id)"
        @update:ratio="setRatio"
        @rotate-left="rotateBy(-90)"
        @rotate-right="rotateBy(90)"
        @flip-h="toggleFlipH"
        @flip-v="toggleFlipV"
      >
        <template #filter-thumb="{ filter }">
          <slot name="filter-thumb" :filter="filter" />
        </template>
      </EditorSidebar>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, toRaw, useTemplateRef, watch } from 'vue';
import EditorCropStage from './EditorCropStage.vue';
import EditorMobileBar from './EditorMobileBar.vue';
import EditorSidebar from './EditorSidebar.vue';
import EditorFloatActions from './EditorFloatActions.vue';
import { DEFAULT_PARAMS, INITIAL_STATE } from './types';
import type { EditorSection, EditorState } from './types';

const props = withDefaults(
  defineProps<{
    /** Accent color for sliders, tabs and highlights. Any CSS color. */
    accent?: string;
    imageWidth?: number;
    imageHeight?: number;
  }>(),
  { accent: '#4a9eff', imageWidth: 0, imageHeight: 0 },
);

const emit = defineEmits<{
  back: [];
  save: [state: EditorState];
  change: [state: EditorState];
  section: [s: EditorSection];
}>();

// Single source of truth: ALL editor state lives here. Every mutation is
// forwarded to the host as a full snapshot via `change`.
const state = reactive<EditorState>({ ...INITIAL_STATE });
const defaults = reactive<Record<string, number>>({ ...DEFAULT_PARAMS });

const section = ref<EditorSection>('crop');
/** Slider currently loaded in the mobile ruler pill. Null = nothing selected. */
const mobileKey = ref<string | null>(null);

/** Whether the state differs from the pristine defaults (drives the save button). */
const hasEdits = computed(() => {
  for (const key of Object.keys(INITIAL_STATE) as (keyof EditorState)[]) {
    if (key === 'comparing') continue;
    if (state[key] !== INITIAL_STATE[key]) return true;
  }
  return false;
});

const cropStage = useTemplateRef('cropStage');

function setParam(key: string, v: number) {
  (state as any)[key] = v;
}

function resetParam(key: string) {
  if (defaults[key] !== undefined) {
    if (key === 'straighten') cropStage.value?.settle();
    (state as any)[key] = defaults[key];
  }
}

function resetAll() {
  for (const k of Object.keys(defaults)) (state as any)[k] = defaults[k];
  state.rotate = 0;
  state.flipH = 1;
  state.flipV = 1;
  state.preset = '';
  state.ratio = 'free';
  state.comparing = false;
  cropStage.value?.resetCrop();
}

function rotateBy(deg: number) {
  cropStage.value?.rotateBy(deg);
}

function toggleFlipH() {
  cropStage.value?.toggleFlipH();
}

function toggleFlipV() {
  cropStage.value?.toggleFlipV();
}

function setRatio(id: string) {
  cropStage.value?.setRatio(id);
}

function setSection(s: EditorSection) {
  section.value = s;
  mobileKey.value = null;
  emit('section', s);
}

function onSave() {
  emit('save', toRaw(state) as EditorState);
}

// Forward every edit as `change` (controls-only: no rendering).
watch(
  state,
  () => {
    const snap = { ...toRaw(state) };
    emit('change', snap);
  },
  { deep: true },
);

// Accent is a plain CSS color prop; the dim variant derives from it in CSS.
const accentStyle = computed(() => ({ '--me-accent': props.accent }) as Record<string, string>);

defineExpose({
  state,
  resetAll,
  setParam,
  resetParam,
});
</script>

<style lang="scss">
@use './editor.scss';
</style>
