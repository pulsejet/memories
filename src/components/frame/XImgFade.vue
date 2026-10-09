<template>
  <div class="ximg-fade">
    <div v-for="layer of layers" :key="layer.id" class="fill-block layer" :class="{ show: layer.loaded }">
      <XImg class="fill-block" :src="layer.url" @load="onLayerLoad(layer)" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue';

import XImg from '@components/frame/XImg.vue';

const props = defineProps<{
  src?: string;
  duration: string;
}>();

interface ILayer {
  id: number;
  url: string;
  loaded: boolean;
}

let seq = 0;
const layers = ref<ILayer[]>([]);

watch(
  () => props.src,
  (url) => {
    if (!url) return;
    const top = layers.value[layers.value.length - 1];
    if (top && top.url === url) return;
    layers.value.push({ id: ++seq, url, loaded: false });
    if (layers.value.length > 3) layers.value.splice(0, layers.value.length - 3);
  },
  { immediate: true },
);

function onLayerLoad(layer: ILayer) {
  layer.loaded = true;
  const id = layer.id;
  window.setTimeout(() => {
    const idx = layers.value.findIndex((l) => l.id === id);
    if (idx > 0) layers.value.splice(0, idx);
  }, parseFloat(props.duration));
}
</script>

<style lang="scss" scoped>
.ximg-fade {
  position: relative;
}

.layer {
  position: absolute;
  inset: 0;
  opacity: 0;
  transition: opacity v-bind(duration) ease;
}

.layer:first-child {
  position: relative;
}

.layer.show {
  opacity: 1;
}
</style>
