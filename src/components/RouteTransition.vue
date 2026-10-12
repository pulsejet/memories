<template>
  <Transition v-if="!isE2E" :name="routeTransition" :css="true">
    <slot />
  </Transition>
  <template v-else>
    <slot />
  </template>
</template>

<script setup lang="ts">
defineProps<{
  /** Transition name applied to the slot content. */
  routeTransition: string;
}>();

// Disable route transitions on E2E to avoid flakiness.
const isE2E = !!window.navigator?.webdriver;
</script>
