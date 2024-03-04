<template>
  <div class="nxsetup-outer">
    <XImg class="banner" :src="banner" :svg-tag="true" />

    <div class="setup-section" v-if="step === 1">
      {{ t('memories', 'You are now logged in to the server!') }}
      <br /><br />
      {{
        t(
          'memories',
          'You can set up automatic uploads from this device using the Nextcloud mobile app. Click the button below to download the app, or skip this step and continue.',
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

    <div class="setup-section" v-if="step === 2">
      {{
        t(
          'memories',
          'Memories can show local media on your device alongside the media on your server. This requires access to the media on this device.',
        )
      }}
      <br /><br />
      {{
        hasMediaPermission
          ? t('memories', 'Access to media has been granted.')
          : t(
              'memories',
              'Access to media is not available yet. If the button below does not work, grant the permission through settings.',
            )
      }}

      <div class="buttons">
        <NcButton type="secondary" class="button" @click="grantMediaPermission" v-if="!hasMediaPermission">
          {{ t('memories', 'Grant permissions') }}
        </NcButton>

        <NcButton
          :type="hasMediaPermission ? 'secondary' : 'primary'"
          class="button"
          @click="step += hasMediaPermission ? 1 : 2"
        >
          {{ hasMediaPermission ? t('memories', 'Continue') : t('memories', 'Skip this step') }}
        </NcButton>
      </div>
    </div>

    <div class="setup-section" v-else-if="step === 3">
      {{ t('memories', 'Choose the folders on this device to show on your timeline.') }}
      {{
        t(
          'memories',
          'If no folders are visible here, you may need to grant the app storage permissions, or wait for the app to index your files.',
        )
      }}
      <br /><br />
      {{ t('memories', 'You can always change this in settings. Note that this does not affect automatic uploading.') }}
      <br />

      <div id="folder-list">
        <div v-if="syncStatus != -1">
          {{ t('memories', 'Synchronizing local files ({n} done).', { n: syncStatus }) }}
          <br />
          {{ t('memories', 'This may take a while. Do not close this window.') }}
        </div>
        <template v-else>
          <NcCheckboxRadioSwitch
            v-for="folder in localFolders"
            :key="folder.id"
            :checked.sync="folder.enabled"
            @update:checked="updateDeviceFolders"
            type="switch"
          >
            {{ folder.name }}
          </NcCheckboxRadioSwitch>
        </template>
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

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcCheckboxRadioSwitch = () => import('@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js');

import * as util from '@services/utils';
import * as nativex from '@native';

import banner from '@assets/banner.svg';

export default defineComponent({
  name: 'NXSetup',

  components: {
    NcButton,
    NcCheckboxRadioSwitch,
  },

  data: () => ({
    banner,
    hasMediaPermission: false,
    step: util.uid ? 1 : 0,
    localFolders: [] as nativex.LocalFolderConfig[],
    syncStatus: -1,
    syncStatusWatch: 0,
  }),

  watch: {
    step() {
      switch (this.step) {
        case 2:
          this.hasMediaPermission = nativex.configHasMediaPermission();
          break;
        case 3:
          this.localFolders = nativex.getLocalFolders();
          break;
        case 4:
          this.$router.replace('/');
          break;
      }
    },
  },

  beforeMount() {
    if (!nativex.has() || !this.step) {
      this.$router.replace('/');
    }
  },

  async mounted() {
    await this.$nextTick();

    // set nativex theme
    nativex.setTheme(getComputedStyle(document.body).getPropertyValue('--color-background-plain'));

    // set up sync status watcher
    this.syncStatusWatch = window.setInterval(() => {
      if (this.hasMediaPermission && this.step === 3) {
        const newStatus = nativex.nativex.getSyncStatus();

        // Refresh local folders if newly reached state -1
        if (newStatus === -1 && this.syncStatus !== -1) {
          this.localFolders = nativex.getLocalFolders();
        }

        this.syncStatus = newStatus;
      }
    }, 500);
  },

  beforeDestroy() {
    nativex.setTheme(); // reset theme
    window.clearInterval(this.syncStatusWatch);
  },

  methods: {
    updateDeviceFolders() {
      nativex.setLocalFolders(this.localFolders);
    },

    async grantMediaPermission() {
      await nativex.configAllowMedia();
      this.hasMediaPermission = nativex.configHasMediaPermission();
    },
  },
});
</script>

<style lang="scss" scoped>
.nxsetup-outer {
  width: 100%;
  height: 100%;
  overflow-y: auto;
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
