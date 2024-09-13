<template>
  <NcAppContent :allowSwipeNavigation="false">
    <div class="outer fill-block" :class="{ show }">
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

        <NcButton @click="finish" class="button" type="primary">
          {{ t('memories', 'Continue to Memories') }}
        </NcButton>
      </div>

      <NcButton @click="begin" class="button" v-if="info">
        {{ t('memories', 'Choose again') }}
      </NcButton>
      <NcButton @click="begin" class="button" type="primary" v-else>
        {{ t('memories', 'Click here to start') }}
      </NcButton>

      <div class="footer">
        {{ t('memories', 'You can always change this later in settings') }}

        <span class="admin-text" v-if="isAdmin">
          <br />
          {{ t('memories', 'If you just installed Memories, visit the admin panel first.') }}
        </span>
      </div>
    </div>
  </NcAppContent>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js';
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';

import UserConfig from '@mixins/UserConfig';

import axios from '@nextcloud/axios';

import banner from '@assets/banner.svg';

import * as utils from '@services/utils';
import { API } from '@services/API';

import type { IDay } from '@typings';

export default defineComponent({
  name: 'FirstStart',
  components: {
    NcAppContent,
    NcButton,
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
    window.setTimeout(() => {
      this.show = true;
    }, 300);
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
  max-width: 450px;
  margin: 0 auto;
  padding: 20px;
  text-align: center;

  transition: opacity 1s ease;
  opacity: 0;
  &.show {
    opacity: 1;
  }

  .title {
    color: var(--color-primary);
    font-size: 2.8em;
    line-height: 1.1em;
    font-family: cursive;
    font-weight: 500;
    margin-top: 10px;
    margin-bottom: 20px;
    width: 100%;

    > .img {
      margin: 0 auto;
      width: 60vw;
      max-width: 400px;
    }
  }

  .error {
    color: red;
    margin-top: 7px;
    font-size: 0.8em;
    line-height: 1.2em;
    font-weight: 500;
    white-space: pre-line;
  }

  .info {
    margin-top: 10px;
    font-weight: bold;
  }

  .button {
    display: inline-block;
    margin: 8px;
  }

  .footer {
    font-size: 0.8em;
  }
}
</style>
