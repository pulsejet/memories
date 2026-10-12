<template>
  <component
    :is="to ? 'router-link' : 'a'"
    class="explore-button"
    v-bind="to ? { to } : { href: '#', role: 'button' }"
    :aria-label="label"
    @click="onClick"
  >
    <span class="button-icon">
      <component :is="icon" :size="20" />
    </span>
    <span class="button-label">{{ label }}</span>
    <ChevronRightIcon class="button-chevron" :size="18" />
  </component>
</template>

<script setup lang="ts">
import type { Component } from 'vue';

import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue';

import * as nativex from '@native';

const props = defineProps<{
  icon: Component;
  label: string;
  to?: string;
}>();

const emit = defineEmits<{
  (e: 'click', event: MouseEvent): void;
}>();

function onClick(event: MouseEvent) {
  nativex.playTouchSound();
  if (!props.to) event.preventDefault();
  emit('click', event);
}
</script>

<style lang="scss" scoped>
.explore-button {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  box-sizing: border-box;
  min-height: 50px;
  padding: 6px 8px 6px 10px;
  border: 0;
  border-radius: 12px;
  background-color: var(--color-background-hover);
  color: var(--color-main-text);
  text-decoration: none;
  cursor: pointer;

  &:hover {
    background-color: var(--color-background-dark);
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 1px;
  }

  .button-icon {
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 9px;
    color: var(--color-primary);
    background-color: color-mix(in srgb, var(--color-primary) 14%, transparent);
  }

  .button-label {
    flex: 1 1 auto;
    min-width: 0;
    font-size: 0.9em;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    @media (max-width: 768px) {
      white-space: normal;
      display: -webkit-box;
      line-clamp: 2;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
    }
  }

  .button-chevron {
    flex: 0 0 auto;
    opacity: 0.45;
  }
}
</style>
