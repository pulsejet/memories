<template>
  <div class="me-mobile-ui" aria-label="Mobile editor controls">
    <div class="me-slider-pill me-glass" :class="{ 'is-on': showRuler }">
      <button
        class="me-s-pill-icon button-vue"
        :class="{ 'is-changed': rulerChanged, 'me-clickable': rulerChanged }"
        :title="rulerChanged ? `Reset ${rulerLabel}` : rulerLabel"
        aria-label="Reset adjustment"
        @click="onRulerReset"
      >
        <component :is="rulerIcon" v-if="rulerIcon" :size="17" />
      </button>
      <div class="me-slider-main">
        <div
          ref="rulerEl"
          class="me-ruler"
          :class="{ 'is-scrolling': scrolling }"
          role="slider"
          tabindex="0"
          :aria-label="rulerLabel"
          :aria-valuemin="rulerDef?.min"
          :aria-valuemax="rulerDef?.max"
          :aria-valuenow="rulerValue"
          :aria-valuetext="rulerText"
          @keydown="onRulerKey"
        >
          <div ref="scrollerEl" class="me-ruler-scroll">
            <div ref="trackEl" class="me-ruler-track" v-html="rulerHtml" />
          </div>
          <div class="me-ruler-center" aria-hidden="true" />
        </div>
      </div>
    </div>

    <div v-if="showRatioPill" class="me-bar me-glass" aria-label="Crop ratios">
      <button
        v-for="r in RATIOS"
        :key="r.id"
        class="me-chip me-clickable button-vue"
        :class="{ 'is-active': state.ratio === r.id }"
        @click="onPickRatio(r.id)"
      >
        <span>{{ r.label }}</span>
      </button>
    </div>

    <div class="me-bar me-glass" aria-label="Options">
      <template v-if="section === 'adjust'">
        <button
          v-for="k in ADJUST_KEYS"
          :key="k"
          class="me-chip me-clickable button-vue"
          :class="{ 'is-active': k === mobileKey, 'is-changed': isChanged(k) }"
          @click="emit('update:mobile-key', k)"
        >
          <span class="me-chip-icon"><component :is="iconFor(k)" :size="17" /></span>
          <span>{{ capitalize(k) }}</span>
          <span class="me-chip-val">{{ formatParamValue(k, state[k as keyof EditorParams]) }}</span>
        </button>
      </template>
      <template v-else-if="section === 'crop'">
        <button class="me-chip me-clickable button-vue" @click="emit('rotate-left')">
          <span class="me-chip-icon"><RotateLeft :size="17" /></span><span>−90°</span>
        </button>
        <button class="me-chip me-clickable button-vue" @click="emit('rotate-right')">
          <span class="me-chip-icon"><RotateRight :size="17" /></span><span>+90°</span>
        </button>
        <button
          class="me-chip me-clickable button-vue"
          :class="{ 'is-active': mobileKey === 'straighten', 'is-changed': isChanged('straighten') }"
          @click="emit('update:mobile-key', 'straighten')"
        >
          <span class="me-chip-icon"><component :is="iconFor('straighten')" :size="17" /></span>
          <span>Straighten</span>
          <span class="me-chip-val">{{ formatParamValue('straighten', state.straighten) }}</span>
        </button>
        <button class="me-chip me-clickable button-vue" @click="emit('flip-h')">
          <span class="me-chip-icon"><FlipHorizontal :size="17" /></span><span>Flip H</span>
        </button>
        <button class="me-chip me-clickable button-vue" @click="emit('flip-v')">
          <span class="me-chip-icon"><FlipVertical :size="17" /></span><span>Flip V</span>
        </button>
        <button
          class="me-chip me-clickable button-vue"
          :class="{ 'is-active': mobileKey === 'ratio' }"
          @click="emit('update:mobile-key', 'ratio')"
        >
          <span class="me-chip-icon"><AspectRatio :size="17" /></span>
          <span>Ratio</span>
          <span class="me-chip-val">{{ ratioLabel }}</span>
        </button>
      </template>
      <template v-else>
        <button
          v-for="f in FILTERS"
          :key="f.id"
          class="me-chip me-clickable button-vue"
          :class="{ 'is-active': state.preset === f.id }"
          @click="emit('update:preset', f.id)"
        >
          <span>{{ f.name }}</span>
        </button>
      </template>
    </div>

    <nav class="me-bar me-glass" aria-label="Editor sections">
      <button
        v-for="t in tabs"
        :key="t.id"
        class="me-m-tab me-clickable button-vue"
        :class="{ 'is-active': section === t.id }"
        @click="emit('update:section', t.id)"
      >
        <component :is="t.icon" :size="18" />
        {{ t.label }}
      </button>
    </nav>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AspectRatio from 'vue-material-design-icons/AspectRatio.vue';
import AutoFix from 'vue-material-design-icons/AutoFix.vue';
import Crop from 'vue-material-design-icons/Crop.vue';
import FlipHorizontal from 'vue-material-design-icons/FlipHorizontal.vue';
import FlipVertical from 'vue-material-design-icons/FlipVertical.vue';
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue';
import RotateRight from 'vue-material-design-icons/RotateRight.vue';
import Tune from 'vue-material-design-icons/Tune.vue';
import { iconFor } from './paramIcons';
import { ADJUST_KEYS, FILTERS, PARAM_DEFS, RATIOS, capitalize, formatParamValue } from './types';
import type { EditorParams, EditorSection, EditorState } from './types';

const props = defineProps<{
  state: EditorState;
  defaults: Record<string, number>;
  section: EditorSection;
  mobileKey: string | null;
}>();

const emit = defineEmits<{
  'update:section': [s: EditorSection];
  'update:mobile-key': [k: string | null];
  'update:param': [key: string, v: number];
  'reset:param': [key: string];
  'update:preset': [id: string];
  'update:ratio': [id: string];
  'rotate-left': [];
  'rotate-right': [];
  'flip-h': [];
  'flip-v': [];
}>();

const tabs = [
  { id: 'crop' as EditorSection, label: 'Crop', icon: Crop },
  { id: 'adjust' as EditorSection, label: 'Adjust', icon: Tune },
  { id: 'filters' as EditorSection, label: 'Filters', icon: AutoFix },
];

const showRuler = computed(() => props.section !== 'filters' && !!rulerDef.value);
const showRatioPill = computed(() => props.section === 'crop' && props.mobileKey === 'ratio');
const ratioLabel = computed(() => RATIOS.find((r) => r.id === props.state.ratio)?.label ?? '');
const rulerDef = computed(() => (props.mobileKey ? PARAM_DEFS[props.mobileKey] : undefined));
const rulerValue = computed(() => (props.mobileKey ? (props.state as any)[props.mobileKey] : 0));
const rulerText = computed(() => (props.mobileKey ? formatParamValue(props.mobileKey, rulerValue.value) : ''));
const rulerLabel = computed(() => (props.mobileKey ? capitalize(props.mobileKey) : ''));
const rulerIcon = computed(() => (props.mobileKey ? iconFor(props.mobileKey) : ''));
const rulerChanged = computed(
  () =>
    !!props.mobileKey &&
    props.defaults[props.mobileKey] !== undefined &&
    (props.state as any)[props.mobileKey] !== props.defaults[props.mobileKey],
);

function isChanged(k: string): boolean {
  return props.defaults[k] !== undefined && (props.state as any)[k] !== props.defaults[k];
}

// ---------------------------------------------------------------------------
// Tick ruler — thumb-less, scroll-driven (ports mockup §12).
// value = min + round(scrollLeft / SPACING) * step.
// ---------------------------------------------------------------------------
const SPACING = 2;
const RULER_GRADIENTS: Record<string, string> = {
  'grad-hue':
    'linear-gradient(to right,hsl(0,100%,55%),hsl(45,100%,52%),hsl(90,100%,48%),hsl(135,100%,42%),hsl(180,100%,42%),hsl(225,100%,62%),hsl(270,100%,62%),hsl(315,100%,55%),hsl(360,100%,55%))',
  'grad-warm': 'linear-gradient(to right,#5b8def,#3a3a3f 48%,#ffb03a)',
  'grad-tint': 'linear-gradient(to right,#69c24a,#3a3a3f 48%,#d95fb0)',
  'grad-sat': 'linear-gradient(to right,#4c4c52,#8a8a90 35%,#ff7847 75%,#ff3d2e)',
};

const rulerEl = ref<HTMLElement | null>(null);
const scrollerEl = ref<HTMLElement | null>(null);
const trackEl = ref<HTMLElement | null>(null);
const rulerHtml = ref('');
const scrolling = ref(false);

let rulerSteps = 0;
let rulerHalf = -1;
let fromScroll = false;
let inSession = false;
let snapTimer: ReturnType<typeof setTimeout> | null = null;
let ro: ResizeObserver | null = null;

function buildRuler() {
  const key = props.mobileKey;
  const def = key ? PARAM_DEFS[key] : undefined;
  if (!key || !def) {
    rulerHtml.value = '';
    return;
  }
  const step = def.step ?? 1;
  rulerSteps = Math.round((def.max - def.min) / step);
  rulerHalf = -1; // force tick repositioning for the new range
  let html = '';
  if (def.cls && RULER_GRADIENTS[def.cls]) {
    html += `<span class="me-ruler-grad" style="background:${RULER_GRADIENTS[def.cls]}"></span>`;
  }
  for (let i = 0; i <= rulerSteps; i++) {
    const v = def.min + i * step;
    if (Math.abs(v / 5 - Math.round(v / 5)) >= 1e-9) continue; // tick every 5 only
    const major = Math.abs(v / 50 - Math.round(v / 50)) < 1e-9;
    const isDef = Math.abs(v - props.defaults[key]) < 1e-9;
    html += `<span class="me-tick${major ? ' is-major' : ''}${isDef ? ' is-def' : ''}" data-v="${v}" data-i="${i}"></span>`;
  }
  rulerHtml.value = html;
  nextTick(layoutRuler);
}

function ticks(): NodeListOf<HTMLElement> {
  return trackEl.value?.querySelectorAll('.me-tick') ?? ([] as unknown as NodeListOf<HTMLElement>);
}

function layoutRuler() {
  const key = props.mobileKey;
  const def = key ? PARAM_DEFS[key] : undefined;
  if (!key || !def || !trackEl.value || !rulerEl.value) return;
  const step = def.step ?? 1;
  const half = rulerEl.value.clientWidth / 2;
  if (half > 0 && Math.abs(half - rulerHalf) > 0.5) {
    rulerHalf = half;
    ticks().forEach((t) => {
      t.style.left = `${half + parseFloat(t.dataset.i!) * SPACING}px`;
    });
    const grad = trackEl.value.querySelector('.me-ruler-grad') as HTMLElement | null;
    if (grad) {
      grad.style.left = `${half}px`;
      grad.style.width = `${rulerSteps * SPACING}px`;
    }
    trackEl.value.style.width = `${half * 2 + rulerSteps * SPACING + 2}px`;
  }
  // Never fight the user's scroll: skip writes mid-session or when the
  // change itself came from scrolling.
  const idx = ((props.state as any)[key] - def.min) / step;
  if (!fromScroll && !inSession && scrollerEl.value) {
    const target = idx * SPACING;
    if (Math.abs(scrollerEl.value.scrollLeft - target) > 0.5) scrollerEl.value.scrollLeft = target;
  }
  // Range highlight: ticks from clamped 0-anchor out to current value.
  const cur = parseFloat((props.state as any)[key]);
  const anchor = Math.min(def.max, Math.max(def.min, 0));
  const lo = Math.min(anchor, cur);
  const hi = Math.max(anchor, cur);
  ticks().forEach((t) => {
    const tv = parseFloat(t.dataset.v!);
    t.classList.toggle('is-hl', tv >= lo - 1e-9 && tv <= hi + 1e-9);
  });
}

function rulerSet(v: number) {
  const key = props.mobileKey;
  const def = key ? PARAM_DEFS[key] : undefined;
  if (!key || !def) return;
  const step = def.step ?? 1;
  v = Math.min(def.max, Math.max(def.min, v));
  v = Math.round(v / step) * step;
  if (Math.abs(v - (props.state as any)[key]) > 1e-9) emit('update:param', key, v);
}

function endRulerScroll() {
  inSession = false;
  scrolling.value = false;
  const key = props.mobileKey;
  const def = key ? PARAM_DEFS[key] : undefined;
  if (!key || !def || !scrollerEl.value) return;
  const step = def.step ?? 1;
  const target = (((props.state as any)[key] - def.min) / step) * SPACING;
  if (Math.abs(scrollerEl.value.scrollLeft - target) > 0.5) scrollerEl.value.scrollLeft = target;
}

function onScroll() {
  const key = props.mobileKey;
  const def = key ? PARAM_DEFS[key] : undefined;
  if (!key || !def || !scrollerEl.value) return;
  inSession = true;
  scrolling.value = true;
  const step = def.step ?? 1;
  const idx = Math.min(rulerSteps, Math.max(0, Math.round(scrollerEl.value.scrollLeft / SPACING)));
  fromScroll = true;
  rulerSet(def.min + idx * step);
  fromScroll = false;
  // Re-highlight without moving the scroll position.
  layoutRuler();
  if (snapTimer) clearTimeout(snapTimer);
  snapTimer = setTimeout(endRulerScroll, 150);
}

function onScrollEnd() {
  if (snapTimer) clearTimeout(snapTimer);
  endRulerScroll();
}

function onWheel(e: WheelEvent) {
  if (!props.mobileKey || !scrollerEl.value) return;
  const d = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
  if (!d) return;
  e.preventDefault();
  scrollerEl.value.scrollLeft += e.deltaMode === 1 ? d * SPACING * 3 : d;
}

function onRulerKey(e: KeyboardEvent) {
  const key = props.mobileKey;
  const def = key ? PARAM_DEFS[key] : undefined;
  if (!key || !def) return;
  const step = def.step ?? 1;
  const big = e.shiftKey ? step * 10 : step;
  const cur = (props.state as any)[key];
  if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
    e.preventDefault();
    rulerSet(cur - big);
  } else if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
    e.preventDefault();
    rulerSet(cur + big);
  } else if (e.key === 'Home') {
    e.preventDefault();
    rulerSet(def.min);
  } else if (e.key === 'End') {
    e.preventDefault();
    rulerSet(def.max);
  }
}

function onRulerReset() {
  if (props.mobileKey && rulerChanged.value) emit('reset:param', props.mobileKey);
}

function onPickRatio(id: string) {
  emit('update:ratio', id);
  emit('update:mobile-key', null);
}

watch(() => props.mobileKey, buildRuler);
watch(
  () => [props.state, props.defaults],
  () => nextTick(layoutRuler),
  { deep: true },
);

onMounted(() => {
  buildRuler();
  scrollerEl.value?.addEventListener('scroll', onScroll, { passive: true });
  if ('onscrollend' in window) scrollerEl.value?.addEventListener('scrollend', onScrollEnd);
  scrollerEl.value?.addEventListener('wheel', onWheel, { passive: false });
  if (window.ResizeObserver && rulerEl.value) {
    ro = new ResizeObserver(() => layoutRuler());
    ro.observe(rulerEl.value);
  }
});

onBeforeUnmount(() => {
  scrollerEl.value?.removeEventListener('scroll', onScroll);
  scrollerEl.value?.removeEventListener('scrollend', onScrollEnd);
  scrollerEl.value?.removeEventListener('wheel', onWheel);
  ro?.disconnect();
  if (snapTimer) clearTimeout(snapTimer);
});
</script>
