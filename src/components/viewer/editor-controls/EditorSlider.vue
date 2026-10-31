<template>
  <div class="me-slider-row" :class="{ 'is-changed': changed }" :data-key="paramKey">
    <span
      :class="['me-s-icon', { 'me-clickable': changed }]"
      :title="label"
      :role="changed ? 'button' : undefined"
      :aria-label="changed ? t('memories', 'Reset {name}', { name: label }) : undefined"
      :tabindex="changed ? 0 : undefined"
      @click="onIconClick"
      @keydown.enter="onIconKey"
      @keydown.space.prevent="onIconKey"
    >
      <component :is="icon" v-if="icon" :size="20" />
    </span>
    <input
      ref="inputEl"
      class="me-slider-input"
      :class="trackClass"
      type="range"
      :min="min"
      :max="max"
      :step="step"
      :value="modelValue"
      :data-param="paramKey"
      :aria-label="label"
      @input="onInput"
      @dblclick="onReset"
    />
    <span class="me-val">{{ display }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import * as nativex from '@native';
import { t } from '@services/l10n';
import { PARAM_NAMES, formatParamValue, type ParamKey } from './types';

const props = defineProps<{
  paramKey: ParamKey;
  modelValue: number;
  defaultValue: number;
  min: number;
  max: number;
  step?: number;
  icon?: Component;
  trackClass?: string;
  label?: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [v: number];
  reset: [];
}>();

const inputEl = ref<HTMLInputElement | null>(null);
const step = computed(() => props.step ?? 1);
const label = computed(() => props.label ?? PARAM_NAMES[props.paramKey]);
const changed = computed(() => props.modelValue !== props.defaultValue);
const display = computed(() => formatParamValue(props.paramKey, props.modelValue));

function paint() {
  const el = inputEl.value;
  if (!el) return;
  const p = ((props.modelValue - props.min) / (props.max - props.min)) * 100;
  el.style.setProperty('--me-p', `${p}%`);
}

function onInput(e: Event) {
  emit('update:modelValue', parseFloat((e.target as HTMLInputElement).value));
}

function onReset() {
  if (changed.value) {
    nativex.playTouchSound();
    emit('reset');
  }
}

function onIconClick() {
  if (changed.value) {
    nativex.playTouchSound();
    emit('reset');
  }
}

function onIconKey() {
  if (changed.value) {
    nativex.playTouchSound();
    emit('reset');
  }
}

watch(() => props.modelValue, paint);
onMounted(paint);
</script>
