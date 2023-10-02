<template>
  <div class="nxsetup-outer">
    <XImg class="banner" :src="banner" :svg-tag="true" />

    <div class="setup-section" v-if="step === 1">
      {{ t('memories', 'You are now logged in to the server!') }}
      <br /><br />
      {{
        t(
          'memories',
          'You can set up automatic uploads from this device using the Nextcloud mobile app. Click the button below to download the app, or skip this step and continue.'
        )
      }}
      <br />

      <div class="buttons">
        <NcButton
          type="secondary"
          class="button"
          href="https://play.google.com/store/apps/details?id=com.nextcloud.client"
        >
          {{ t('memories', 'Set up automatic upload') }}
        </NcButton>

        <NcButton type="primary" class="button" @click="step++">
          {{ t('memories', 'Continue') }}
        </NcButton>
      </div>
    </div>

    <div class="setup-section" v-else-if="step === 2">
      {{ t('memories', 'Choose the folders on this device to show on your timeline.') }}
      {{
        t(
          'memories',
          'If no folders are visible here, you may need to grant the app storage permissions, or wait for the app to index your files.'
        )
      }}
      <br /><br />
      {{ t('memories', 'You can always change this in settings. Note that this does not affect automatic uploading.') }}
      <br />

      <div id="folder-list">
        <NcCheckboxRadioSwitch
          v-for="folder in localFolders"
          :key="folder.id"
          :checked.sync="folder.enabled"
          @update:checked="updateDeviceFolders"
          type="switch"
        >
          {{ folder.name }}
        </NcCheckboxRadioSwitch>
      </div>

      <div class="buttons">
        <NcButton type="secondary" class="button" @click="step++">
          {{ t('memories', 'Finish') }}
        </NcButton>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import * as nativex from '../native';
import * as util from '../services/utils';

import NcButton from '@nextcloud/vue/dist/Components/NcButton';
const NcCheckboxRadioSwitch = () => import('@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch');

import banner from '../assets/banner.svg';

export default defineComponent({
  name: 'NXSetup',

  components: {
    NcButton,
    NcCheckboxRadioSwitch,
  },

  data: () => ({
    banner,
    step: util.uid ? 1 : 0,
    localFolders: [] as nativex.LocalFolderConfig[],
  }),

  watch: {
    step() {
      switch (this.step) {
        case 2:
          this.localFolders = nativex.getLocalFolders();
          break;
        case 3:
          this.$router.push('/');
          break;
      }
    },
  },

  beforeMount() {
    if (!nativex.has() || !this.step) {
      this.$router.push('/');
    }
  },

  methods: {
    updateDeviceFolders() {
      nativex.setLocalFolders(this.localFolders);
    },
  },
});
</script>

<style lang="scss" scoped>
.nxsetup-outer {
  width: 100%;
  height: 100%;
  background-color: var(--color-background-plain);
  color: var(--color-primary-text);
  text-align: center;

  .setup-section {
    margin: 0 auto;
    width: 90%;
    max-width: 500px;
  }

  .banner {
    padding: 30px 20px;
    :deep > svg {
      width: 60%;
      max-width: 400px;
    }
  }

  .buttons {
    margin-top: 20px;
    .button {
      margin: 10px auto;
    }
  }

  #folder-list {
    background: var(--color-main-background);
    color: var(--color-main-text);
    padding: 10px;
    margin-top: 15px;
    border-radius: 20px;

    .checkbox-radio-switch {
      margin-left: 10px;
      :deep .checkbox-radio-switch__label {
        min-height: unset;
      }
    }
  }
}
</style>
