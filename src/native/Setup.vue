<template>
  <div class="nxsetup-outer native-auth">
    <div class="orbs" aria-hidden="true"><span></span><span></span></div>

    <div class="card">
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
            variant="secondary"
            class="button button-white"
            href="https://play.google.com/store/apps/details?id=com.nextcloud.client"
          >
            {{ t('memories', 'Set up automatic upload') }}
          </NcButton>

          <NcButton variant="primary" class="button" @click="step++">
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
          <NcButton
            variant="secondary"
            class="button button-white"
            @click="grantMediaPermission"
            v-if="!hasMediaPermission"
          >
            {{ t('memories', 'Grant permissions') }}
          </NcButton>

          <NcButton
            :variant="hasMediaPermission ? 'secondary' : 'primary'"
            class="button"
            :class="{ 'button-white': hasMediaPermission }"
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
        {{
          t('memories', 'You can always change this in settings. Note that this does not affect automatic uploading.')
        }}
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
              v-model="folder.enabled"
              @update:model-value="updateDeviceFolders"
              type="switch"
            >
              {{ folder.name }}
            </NcCheckboxRadioSwitch>
          </template>
        </div>

        <div class="buttons">
          <NcButton variant="secondary" class="button button-white" @click="step++">
            {{ t('memories', 'Finish') }}
          </NcButton>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, defineAsyncComponent } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
const NcCheckboxRadioSwitch = defineAsyncComponent(() => import('@nextcloud/vue/components/NcCheckboxRadioSwitch'));

import * as util from '@services/utils';
import * as nativex from '@native';
import XImg from '@components/frame/XImg.vue';

import banner from '@assets/banner.svg';

export default defineComponent({
  name: 'NXSetup',

  components: {
    NcButton,
    NcCheckboxRadioSwitch,
    XImg,
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

    // Transparent system bars so the gradient draws edge-to-edge (same as welcome/waiting)
    nativex.nativex?.setTransparentBars?.(true, true);

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

  beforeUnmount() {
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
  .banner {
    width: 172px;
    max-width: 60vw;
    margin: 0 auto 16px;
    color: #fff;
    filter: drop-shadow(0 6px 20px rgba(0, 0, 0, 0.3));
    > :deep(svg) {
      width: 100%;
      height: auto;
    }
  }

  .setup-section {
    font-size: 14.5px;
    line-height: 1.6;
  }

  .buttons {
    margin-top: 20px;
    .button {
      margin: 10px auto;
    }

    .button-vue--primary {
      color: #fff;

      &:hover:not(:disabled) {
        color: #fff;
      }
    }

    // Secondary NcButtons use a theme tint that looks near-black in dark
    // mode, so use the white welcome-series style on the dark card.
    .button-white {
      background-color: #fff;
      border-color: #fff;
      color: #0f3a5f;

      &:hover:not(:disabled) {
        background-color: #dcecfb;
        border-color: #dcecfb;
        color: #0f3a5f;
      }

      &:active:not(:disabled) {
        background-color: #c9e0f7;
        border-color: #c9e0f7;
        color: #0f3a5f;
      }
    }
  }

  #folder-list {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 16px;
    padding: 12px 14px;
    margin-top: 15px;
    text-align: left;

    .checkbox-radio-switch {
      margin-left: 10px;
      color: #fff;

      // theme hover wash is light and would hide the white label
      :deep(.checkbox-radio-switch__content:hover) {
        background-color: rgba(255, 255, 255, 0.12);
      }

      :deep(.checkbox-radio-switch__label) {
        min-height: unset;
      }
    }
  }
}
</style>

<style scoped src="../styles/native-auth.css"></style>
