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

        <EditorFloatActions :save-label="saveLabel" @close="emit('back')" @save="onSave" />
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
        />
      </div>

      <EditorSidebar
        :state="state"
        :defaults="defaults"
        :section="section"
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
        <template #filter-thumb="{ filter, index }">
          <slot name="filter-thumb" :filter="filter" :index="index" />
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
    saveLabel?: string;
    /** Accent color for sliders, tabs and highlights. Any CSS color. */
    accent?: string;
    /** Controlled state snapshot. Omit for uncontrolled (internal) state. */
    modelValue?: EditorState | null;
    imageWidth?: number;
    imageHeight?: number;
  }>(),
  { saveLabel: 'Save', accent: '#4a9eff', modelValue: null, imageWidth: 0, imageHeight: 0 },
);

const emit = defineEmits<{
  back: [];
  save: [state: EditorState];
  change: [state: EditorState];
  'update:modelValue': [state: EditorState];
}>();

// Single source of truth: ALL editor state lives here. Every mutation is
// forwarded to the host as a full snapshot via `change` / `update:modelValue`.
const state = reactive<EditorState>({ ...INITIAL_STATE });
const defaults = reactive<Record<string, number>>({ ...DEFAULT_PARAMS });

const section = ref<EditorSection>('crop');
/** Slider currently loaded in the mobile ruler pill. Null = nothing selected. */
const mobileKey = ref<string | null>(null);

const cropStage = useTemplateRef('cropStage');

// Controlled mode: reflect an external snapshot into the internal store.
// Uncontrolled (default): the store is the source of truth.
// Guarded so echoing our own `update:modelValue` back does not loop.
watch(
  () => props.modelValue,
  (snap) => {
    if (!snap) return;
    for (const k of Object.keys(snap) as (keyof EditorState)[]) {
      if ((state as any)[k] !== (snap as any)[k]) (state as any)[k] = (snap as any)[k];
    }
  },
  { deep: true },
);

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
  state.zoom = 1;
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
}

function onSave() {
  emit('save', toRaw(state) as EditorState);
}

// Forward every edit as `change` + v-model update (controls-only: no rendering).
watch(
  state,
  () => {
    const snap = { ...toRaw(state) };
    emit('change', snap);
    emit('update:modelValue', snap);
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
