<template>
  <NcActions v-model:open="menuOpen" :aria-label="t('memories', 'Settings')" placement="top" @open="view = 'main'">
    <template #icon>
      <CogIcon :size="24" />
    </template>

    <template v-if="view === 'main'">
      <NcActionButton
        v-if="qualityList.length > 1"
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
      <NcActionButton key="m-vc-nav-speed" is-menu :close-after-click="false" :name="speedRow" @click="view = 'speed'">
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
        v-for="option in qualityList"
        :key="option.id"
        type="radio"
        v-model="quality"
        :value="option.id"
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

import type { QualityAwareVideoElement, QualityOption } from '../PsVideoQuality';

import { t } from '@services/l10n';

const props = defineProps<{
  video?: HTMLVideoElement | QualityAwareVideoElement | null;
}>();

const menuOpen = ref(false);
const view = ref<'main' | 'quality' | 'speed'>('main');
const quality = ref('auto');
const speed = ref('1');

const qualityList = ref<QualityOption[]>([]);
const speedOptions = ['0.5', '0.75', '1', '1.25', '1.5', '2.0'];

function formatBitrate(bps: number) {
  if (bps >= 1000000) return `${(bps / 1000000).toFixed(1)} Mbps`;
  return `${Math.round(bps / 1000)} kbps`;
}

function qualityLabel(option: QualityOption, withBitrate = true) {
  if (option.id === 'auto') return t('memories', 'Auto');
  let label = option.size + 'p';
  if (withBitrate && option.bitrate) {
    label += ' \u00B7 ' + formatBitrate(option.bitrate);
  }
  return label;
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
  const current = qualityList.value.find((option) => option.id === quality.value) ?? qualityList.value[0];
  return current ? `${t('memories', 'Quality')} \u00B7 ${qualityLabel(current, false)}` : t('memories', 'Quality');
});
const speedRow = computed(() => `${t('memories', 'Speed')} \u00B7 ${speedLabel(speed.value)}`);

function onRateChange() {
  if (!props.video) return;
  const rate = props.video.playbackRate;
  speed.value = speedOptions.find((option) => parseFloat(option) === rate) ?? String(rate);
}

function onQualityList() {
  if (!props.video || !('qualityList' in props.video)) {
    qualityList.value = [];
    return;
  }
  qualityList.value = props.video.qualityList;
}

function onQualitySelected() {
  if (props.video && 'currentQuality' in props.video) {
    quality.value = props.video.currentQuality;
  }
}

function attachVideo() {
  if (!props.video) return;
  onRateChange();
  onQualityList();
  onQualitySelected();
  props.video.addEventListener('ratechange', onRateChange);
  props.video.addEventListener('quality-list', onQualityList);
  props.video.addEventListener('quality-selected', onQualitySelected);
}

function detachVideo(el: HTMLVideoElement | null | undefined = props.video) {
  el?.removeEventListener('ratechange', onRateChange);
  el?.removeEventListener('quality-list', onQualityList);
  el?.removeEventListener('quality-selected', onQualitySelected);
}

watch(quality, (value) => {
  if (props.video && 'currentQuality' in props.video) {
    props.video.currentQuality = value;
  }
});

watch(speed, (value) => {
  if (props.video && props.video.playbackRate !== parseFloat(value)) {
    props.video.playbackRate = parseFloat(value);
  }
});

onMounted(attachVideo);
onBeforeUnmount(detachVideo);
</script>
