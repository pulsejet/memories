<template>
  <NcActions v-model:open="menuOpen" :aria-label="t('memories', 'Settings')" placement="top" @open="view = 'main'">
    <template #icon>
      <CogIcon :size="26" />
    </template>

    <template v-if="view === 'main'">
      <NcActionButton
        key="m-vc-nav-quality"
        is-menu
        :close-after-click="false"
        :name="qualityRow"
        @click="view = 'quality'"
      >
        <template #icon>
          <TuneIcon :size="20" />
        </template>
      </NcActionButton>
      <NcActionButton
        key="m-vc-nav-speed"
        is-menu
        :close-after-click="false"
        :name="speedRow"
        @click="view = 'speed'"
      >
        <template #icon>
          <SpeedometerIcon :size="20" />
        </template>
      </NcActionButton>
    </template>

    <template v-else-if="view === 'quality'">
      <NcActionButton
        key="m-vc-back-quality"
        :is-menu="false"
        :close-after-click="false"
        :name="t('memories', 'Back')"
        @click="view = 'main'"
      >
        <template #icon>
          <ArrowLeftIcon :size="20" />
        </template>
      </NcActionButton>
      <NcActionCaption :name="t('memories', 'Quality')" />
      <NcActionButton
        v-for="option in qualityOptions"
        :key="option"
        type="radio"
        v-model="quality"
        :value="option"
        :name="qualityLabel(option)"
        @click="menuOpen = false"
      />
    </template>

    <template v-else>
      <NcActionButton
        key="m-vc-back-speed"
        :is-menu="false"
        :close-after-click="false"
        :name="t('memories', 'Back')"
        @click="view = 'main'"
      >
        <template #icon>
          <ArrowLeftIcon :size="20" />
        </template>
      </NcActionButton>
      <NcActionCaption :name="t('memories', 'Speed')" />
      <NcActionButton
        v-for="option in speedOptions"
        :key="option"
        type="radio"
        v-model="speed"
        :value="option"
        :name="speedLabel(option)"
        @click="menuOpen = false"
      >
        <template v-if="option === '1'" #icon>
          <PlayIcon :size="20" />
        </template>
      </NcActionButton>
    </template>
  </NcActions>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import NcActionButton from '@nextcloud/vue/components/NcActionButton';
import NcActionCaption from '@nextcloud/vue/components/NcActionCaption';
import NcActions from '@nextcloud/vue/components/NcActions';
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue';
import CogIcon from 'vue-material-design-icons/Cog.vue';
import PlayIcon from 'vue-material-design-icons/Play.vue';
import TuneIcon from 'vue-material-design-icons/Tune.vue';
import SpeedometerIcon from 'vue-material-design-icons/Speedometer.vue';

import { t } from '@services/l10n';

const props = defineProps<{
  video?: HTMLVideoElement | null;
}>();

const menuOpen = ref(false);
const view = ref<'main' | 'quality' | 'speed'>('main');
const quality = ref('auto');
const speed = ref('1');

const qualityOptions = ['auto', '1080p', '720p', '480p', '360p'];
const speedOptions = ['0.5', '0.75', '1', '1.25', '1.5', '2.0'];

function qualityLabel(option: string) {
  return option === 'auto' ? t('memories', 'Auto') : option;
}

function speedLabel(option: string) {
  return option === '1' ? t('memories', 'Normal') : `${option}x`;
}

const qualityRow = computed(() => `${t('memories', 'Quality')} · ${qualityLabel(quality.value)}`);
const speedRow = computed(() => `${t('memories', 'Speed')} · ${speedLabel(speed.value)}`);

function onRateChange() {
  if (!props.video) return;
  const rate = props.video.playbackRate;
  speed.value = speedOptions.find((option) => parseFloat(option) === rate) ?? String(rate);
}

function attachVideo() {
  if (!props.video) return;
  speed.value = String(props.video.playbackRate);
  props.video.addEventListener('ratechange', onRateChange);
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  el?.removeEventListener('ratechange', onRateChange);
}

watch(speed, (value) => {
  if (props.video && props.video.playbackRate !== parseFloat(value)) {
    props.video.playbackRate = parseFloat(value);
  }
});

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>
