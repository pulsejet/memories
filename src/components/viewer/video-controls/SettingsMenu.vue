<template>
  <NcActions v-model:open="menuOpen" :aria-label="t('memories', 'Settings')" placement="top" @open="view = 'main'">
    <template #icon>
      <CogIcon :size="26" />
    </template>

    <template v-if="view === 'main'">
      <NcActionButton
        v-if="qualityOptions.length > 1"
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
        :key="option.label"
        type="radio"
        v-model="quality"
        :value="option.label"
        :name="qualityLabel(option)"
        @click="menuOpen = false"
      >
        <template #icon>
          <component :is="qualityIcon(option.size)" :size="20" />
        </template>
      </NcActionButton>
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
import AutoFixIcon from 'vue-material-design-icons/AutoFix.vue';
import CogIcon from 'vue-material-design-icons/Cog.vue';
import HighDefinitionBoxIcon from 'vue-material-design-icons/HighDefinitionBox.vue';
import HighDefinitionIcon from 'vue-material-design-icons/HighDefinition.vue';
import PlayIcon from 'vue-material-design-icons/Play.vue';
import TuneIcon from 'vue-material-design-icons/Tune.vue';
import UltraHighDefinitionIcon from 'vue-material-design-icons/UltraHighDefinition.vue';
import SpeedometerIcon from 'vue-material-design-icons/Speedometer.vue';
import StandardDefinitionIcon from 'vue-material-design-icons/StandardDefinition.vue';

import { t } from '@services/l10n';

const props = defineProps<{
  video?: HTMLVideoElement | null;
}>();

const menuOpen = ref(false);
const view = ref<'main' | 'quality' | 'speed'>('main');
const quality = ref('auto');
const speed = ref('1');

interface QualityOption {
  label: string;
  size: number;
}

const qualityOptions: QualityOption[] = [
  { label: 'auto', size: -1 },
  { label: '2160p', size: 2160 },
  { label: '1080p', size: 1080 },
  { label: '720p', size: 720 },
  { label: '480p', size: 480 },
  { label: '360p', size: 360 },
];
const speedOptions = ['0.5', '0.75', '1', '1.25', '1.5', '2.0'];

function qualityLabel(option: QualityOption) {
  return option.label === 'auto' ? t('memories', 'Auto') : option.label;
}

function qualityIcon(size: number) {
  if (size < 0) return AutoFixIcon;
  if (size >= 1620) return UltraHighDefinitionIcon;
  if (size >= 900) return HighDefinitionBoxIcon;
  if (size >= 600) return HighDefinitionIcon;
  return StandardDefinitionIcon;
}

function speedLabel(option: string) {
  return option === '1' ? t('memories', 'Normal') : `${option}x`;
}

const qualityRow = computed(() => {
  const current = qualityOptions.find((option) => option.label === quality.value) ?? qualityOptions[0];
  return `${t('memories', 'Quality')} · ${qualityLabel(current)}`;
});
const speedRow = computed(() => `${t('memories', 'Speed')} · ${speedLabel(speed.value)}`);

function onRateChange() {
  if (!props.video) return;
  const rate = props.video.playbackRate;
  speed.value = speedOptions.find((option) => parseFloat(option) === rate) ?? String(rate);
}

function attachVideo() {
  if (!props.video) return;
  onRateChange();
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
