<template>
  <!-- Directly use SVG element if possible -->
  <div class="svg" v-if="svg" v-html="svg"></div>

  <!-- Otherwise use img element -->
  <img v-else :alt="alt" :src="dataSrc" @load="load" />
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { constants } from '@services/constants';
import { isAbortError, useAbort } from '@services/utils/abort';
import { fetchImage, sticky } from './XImgCache';

const BLANK_IMG: string = constants.BLANK_IMG;

defineOptions({
  name: 'XImg',
});

const props = withDefaults(
  defineProps<{
    src?: string;
    alt?: string;
    svgTag?: boolean;
  }>(),
  {
    alt: '',
    svgTag: false,
  },
);

const emit = defineEmits<{
  load: [src: string];
  error: [error: Error];
}>();

const dataSrc = ref(BLANK_IMG);
let blobLocked = false;
const abort = useAbort();

watch(
  () => props.src,
  () => {
    loadImage();
  },
);

onMounted(() => {
  loadImage();
});

onBeforeUnmount(() => {
  // Free up the blob if it was locked
  freeBlob();
});

const svg = computed(() => {
  if (props.svgTag && dataSrc.value.startsWith('data:image/svg+xml')) {
    return window.atob(dataSrc.value.split(',')[1]);
  }
  return null;
});

async function loadImage() {
  if (!props.src) return;

  // Free up current blob if it was locked
  freeBlob();

  // Just set src if not http
  if (props.src.startsWith('data:') || props.src.startsWith('blob:')) {
    dataSrc.value = props.src;
    return;
  }

  // Fetch image with worker
  try {
    const signal = abort.renew();
    const blobSrc = await fetchImage(props.src, { signal });
    signal.throwIfAborted();
    dataSrc.value = blobSrc;

    // Locking is needed primary for thumbnails,
    // since photoswipe uses the thumb url for the animated zoom-in
    lockBlob();
  } catch (error: any) {
    if (isAbortError(error)) return;
    dataSrc.value = BLANK_IMG;
    emit('error', error);
    console.error('Failed to load XImg', error);
  }
}

function load() {
  if (dataSrc.value === BLANK_IMG) return;
  emit('load', dataSrc.value);
}

function lockBlob() {
  sticky(dataSrc.value, 1);
  blobLocked = true;
}

function freeBlob() {
  if (!blobLocked) return;
  sticky(dataSrc.value, -1);
  blobLocked = false;
}
</script>

<style lang="scss" scoped>
div.svg > :deep(svg) {
  width: 100%;
  height: 100%;
}
</style>
