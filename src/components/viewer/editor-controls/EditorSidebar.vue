<template>
  <aside class="me-sidebar">
    <nav class="me-tabs" role="tablist" aria-label="Editor sections">
      <button
        v-for="t in tabs"
        :key="t.id"
        ref="tabEls"
        class="me-tab me-clickable button-vue"
        :class="{ 'is-active': section === t.id }"
        role="tab"
        :aria-selected="section === t.id"
        @click="emit('update:section', t.id)"
      >
        <component :is="t.icon" :size="21" />
        {{ t.label }}
      </button>
      <span ref="indicatorEl" class="me-tab-indicator" />
    </nav>

    <div class="me-panel-scroll">
      <section class="me-panel" :class="{ 'is-active': section === 'adjust' }" role="tabpanel">
        <div class="me-group-label">Light</div>
        <EditorSlider
          v-for="d in lightSliders"
          :key="d.key"
          :param-key="d.key"
          :model-value="state[d.key as keyof EditorParams]"
          :default-value="defaults[d.key] ?? d.val"
          :min="d.min"
          :max="d.max"
          :step="d.step"
          :icon="iconFor(d.key)"
          :track-class="d.cls ? `me-${d.cls}` : undefined"
          @update:model-value="(v) => emit('update:param', d.key, v)"
          @reset="emit('reset:param', d.key)"
        />
        <div class="me-group-label">Color</div>
        <EditorSlider
          v-for="d in colorSliders"
          :key="d.key"
          :param-key="d.key"
          :model-value="state[d.key as keyof EditorParams]"
          :default-value="defaults[d.key] ?? d.val"
          :min="d.min"
          :max="d.max"
          :step="d.step"
          :icon="iconFor(d.key)"
          :track-class="d.cls ? `me-${d.cls}` : undefined"
          @update:model-value="(v) => emit('update:param', d.key, v)"
          @reset="emit('reset:param', d.key)"
        />
      </section>

      <section class="me-panel" :class="{ 'is-active': section === 'crop' }" role="tabpanel">
        <div class="me-group-label">Aspect ratio</div>
        <div class="me-ratio-grid">
          <button
            v-for="r in RATIOS"
            :key="r.id"
            class="me-ratio-btn me-clickable button-vue"
            :class="{ 'is-active': state.ratio === r.id }"
            @click="emit('update:ratio', r.id)"
          >
            <span class="me-box" :style="boxStyle(r.id)" />
            {{ r.label }}
          </button>
        </div>
        <div class="me-group-label">Straighten</div>
        <EditorSlider
          param-key="straighten"
          :model-value="state.straighten"
          :default-value="defaults.straighten ?? 0"
          :min="-45"
          :max="45"
          :step="0.5"
          :icon="iconFor('straighten')"
          @update:model-value="(v) => emit('update:param', 'straighten', v)"
          @reset="emit('reset:param', 'straighten')"
        />
        <div class="me-group-label">Transform</div>
        <div class="me-tool-grid">
          <button class="me-tool-btn me-clickable button-vue" @click="emit('rotate-left')">
            <RotateLeft :size="20" />−90°
          </button>
          <button class="me-tool-btn me-clickable button-vue" @click="emit('rotate-right')">
            <RotateRight :size="20" />+90°
          </button>
          <button
            class="me-tool-btn me-clickable button-vue"
            :class="{ 'is-active': state.flipH < 0 }"
            @click="emit('flip-h')"
          >
            <FlipHorizontal :size="20" />Flip H
          </button>
          <button
            class="me-tool-btn me-clickable button-vue"
            :class="{ 'is-active': state.flipV < 0 }"
            @click="emit('flip-v')"
          >
            <FlipVertical :size="20" />Flip V
          </button>
        </div>
      </section>

      <section class="me-panel" :class="{ 'is-active': section === 'filters' }" role="tabpanel">
        <div class="me-group-label">Presets</div>
        <div class="me-filter-grid">
          <button
            v-for="(f, i) in FILTERS"
            :key="f.id"
            class="me-filter-card me-clickable button-vue"
            :class="{ 'is-active': state.preset === f.id }"
            @click="emit('update:preset', f.id)"
          >
            <span class="me-thumb">
              <slot name="filter-thumb" :filter="f" :index="i" />
            </span>
            <span class="me-filter-name">{{ f.name }}</span>
          </button>
        </div>
      </section>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AutoFix from 'vue-material-design-icons/AutoFix.vue';
import Crop from 'vue-material-design-icons/Crop.vue';
import FlipHorizontal from 'vue-material-design-icons/FlipHorizontal.vue';
import FlipVertical from 'vue-material-design-icons/FlipVertical.vue';
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue';
import RotateRight from 'vue-material-design-icons/RotateRight.vue';
import Tune from 'vue-material-design-icons/Tune.vue';
import EditorSlider from './EditorSlider.vue';
import { iconFor } from './paramIcons';
import { FILTERS, RATIOS, SLIDER_DEFS } from './types';
import type { EditorParams, EditorSection, EditorState } from './types';

const props = defineProps<{
  state: EditorState;
  defaults: Record<string, number>;
  section: EditorSection;
}>();

const emit = defineEmits<{
  'update:section': [s: EditorSection];
  'update:param': [key: string, v: number];
  'reset:param': [key: string];
  'update:preset': [id: string];
  'update:ratio': [id: string];
  'rotate-left': [];
  'rotate-right': [];
  'flip-h': [];
  'flip-v': [];
}>();

const lightSliders = SLIDER_DEFS.filter((d) => d.group === 'light');
const colorSliders = SLIDER_DEFS.filter((d) => d.group === 'color');

const tabs = [
  { id: 'crop' as EditorSection, label: 'Crop', icon: Crop },
  { id: 'adjust' as EditorSection, label: 'Adjust', icon: Tune },
  { id: 'filters' as EditorSection, label: 'Filters', icon: AutoFix },
];

const RATIO_BOX: Record<string, string> = {
  free: 'width:24px;height:16px;border-style:dashed',
  orig: 'width:26px;height:15px',
  '1': 'width:18px;height:18px',
  '1.333': 'width:22px;height:17px',
  '1.5': 'width:24px;height:16px',
  '1.778': 'width:27px;height:15px',
  '0.8': 'width:15px;height:19px',
  '0.5625': 'width:13px;height:22px',
};

function boxStyle(id: string): string {
  return RATIO_BOX[id] ?? 'width:20px;height:20px';
}

// Sliding Material indicator, positioned in px like the mockup.
const tabEls = ref<HTMLElement[]>([]);
const indicatorEl = ref<HTMLElement | null>(null);
let ro: ResizeObserver | null = null;

function moveIndicator() {
  const idx = tabs.findIndex((t) => t.id === props.section);
  const btn = tabEls.value[idx];
  const ind = indicatorEl.value;
  if (!btn || !ind) return;
  ind.style.left = `${btn.offsetLeft}px`;
  ind.style.width = `${btn.offsetWidth}px`;
}

watch(
  () => props.section,
  () => nextTick(moveIndicator),
);
onMounted(() => {
  moveIndicator();
  requestAnimationFrame(moveIndicator);
  if (document.fonts?.ready) document.fonts.ready.then(() => moveIndicator());
  // Container width can change without a window resize (custom element
  // in a shadow root), so observe the tab bar itself.
  if (window.ResizeObserver && indicatorEl.value?.parentElement) {
    ro = new ResizeObserver(() => moveIndicator());
    ro.observe(indicatorEl.value.parentElement);
  } else {
    window.addEventListener('resize', moveIndicator);
  }
});
onBeforeUnmount(() => {
  ro?.disconnect();
  window.removeEventListener('resize', moveIndicator);
});
</script>
