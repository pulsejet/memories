<template>
  <div class="native-auth">
    <div class="card outer" :class="{ show }">
      <div class="title">
        <XImg class="img" :src="banner" :svg-tag="true" />
      </div>

      <div class="text">
        {{ t('memories', 'A better photos experience awaits you') }} <br />
        {{ t('memories', 'Choose the root folder of your timeline to begin') }}
      </div>

      <div class="error" v-if="error">
        {{ error }}
      </div>

      <div class="info" v-if="info">
        {{ info }} <br />

        <NcButton @click="finish" class="button" variant="primary">
          {{ t('memories', 'Continue to Memories') }}
        </NcButton>
      </div>

      <div class="buttons">
        <NcButton @click="begin" class="button" v-if="info">
          {{ t('memories', 'Choose again') }}
        </NcButton>
        <NcButton @click="begin" class="button" variant="primary" v-else>
          {{ t('memories', 'Click here to start') }}
        </NcButton>
      </div>

      <div class="footer">
        {{ t('memories', 'You can always change this later in settings') }}

        <span class="admin-text" v-if="isAdmin">
          <br />
          {{ t('memories', 'If you just installed Memories, visit the admin panel first.') }}
        </span>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcButton from '@nextcloud/vue/components/NcButton';
import XImg from '@components/frame/XImg.vue';
import * as nativex from '@native';

import UserConfig from '@mixins/UserConfig';

import axios from '@nextcloud/axios';

import banner from '@assets/banner.svg';

import * as utils from '@services/utils';
import { API } from '@services/API';

import type { IDay } from '@typings';

export default defineComponent({
  name: 'FirstStart',
  components: {
    NcButton,
    XImg,
  },

  mixins: [UserConfig],

  data: () => ({
    banner,
    error: '',
    info: '',
    show: false,
    chosenPath: '',
  }),

  mounted() {
    nativex.setTheme('#174a7d', true);
    document.getElementById('content')?.classList.add('no-margins');
    window.setTimeout(() => {
      this.show = true;
    }, 300);
  },

  beforeUnmount() {
    nativex.setTheme(); // restore server theme
    document.getElementById('content')?.classList.remove('no-margins');
  },

  computed: {
    isAdmin(): boolean {
      return utils.isAdmin;
    },
  },

  methods: {
    async begin() {
      const path = await utils.chooseNcFolder(this.t('memories', 'Choose the root of your timeline'));

      // Get folder days
      this.error = '';
      this.info = '';
      const url = API.Q(API.DAYS(), { folder: path, recursive: 1 });
      const res = await axios.get<IDay[]>(url);

      // Check response
      if (res.status !== 200) {
        this.error = this.t('memories', 'The selected folder does not seem to be valid. Try again.');
        return;
      }

      // Count total photos
      const n = res.data.reduce((acc, day) => acc + day.count, 0);
      this.info = this.n('memories', 'Found {n} item in {path}', 'Found {n} items in {path}', n, {
        n,
        path,
      });
      this.chosenPath = path;

      // Check if nothing was found
      if (n === 0) {
        this.error =
          this.t('memories', 'No photos were found in the selected folder.') +
          '\n' +
          this.t('memories', 'This can happen because your media is still indexing.');

        if (this.isAdmin) {
          this.error +=
            '\n\n' + this.t('memories', 'Visit the admin panel to make sure Memories is configured correctly.');
        }
        return;
      }
    },

    async finish() {
      this.show = false;
      await new Promise((resolve) => setTimeout(resolve, 500));
      this.config.timeline_path = this.chosenPath;
      await this.updateSetting('timeline_path', 'timelinePath');
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  transition: opacity 1s ease;
  opacity: 0;
  &.show {
    opacity: 1;
  }

  .title {
    margin: 0 auto 16px;
    color: #fff;

    > .img {
      margin: 0 auto;
      width: 172px;
      max-width: 60vw;
    }
  }

  .text {
    font-size: 14.5px;
    line-height: 1.6;
  }

  .error {
    color: #ffb4b4;
    margin-top: 10px;
    font-size: 13px;
    line-height: 1.5;
    font-weight: 600;
    white-space: pre-line;
  }

  .info {
    margin-top: 14px;
    font-weight: bold;

    .button {
      margin: 12px auto 0;
    }
  }

  .buttons {
    margin-top: 20px;

    .button {
      margin: 10px auto;
    }
  }

  .info + .buttons {
    margin-top: 8px;
  }

  .footer {
    margin-top: 20px;
    font-size: 12.5px;
    opacity: 0.75;
  }
}
</style>

<style scoped src="../styles/native-auth.css"></style>
