<template>
  <Modal ref="modal" @close="cleanup" size="normal" v-if="show" :sidebar="sidebar">
    <template #title>
      {{ t('memories', 'Link Sharing') }}
    </template>

    <div v-if="isRoot">
      {{ t('memories', 'You cannot share the root folder') }}
    </div>
    <div v-else>
      {{ t('memories', 'Public link shares are available to people outside Nextcloud.') }}
      <br />
      {{ t('memories', 'You may create or update permissions on public links using the sidebar.') }}
      <br />
      {{ t('memories', 'Click a link to copy to clipboard.') }}
    </div>

    <div class="links">
      <ul>
        <NcListItem
          v-for="share of shares"
          :name="share.label || t('memories', 'Share link')"
          :key="share.id"
          :bold="false"
          :href="share.url"
          :compact="true"
          @click.prevent="shareOrCopy(share.url)"
        >
          <template #icon>
            <LinkIcon class="avatar" :size="20" />
          </template>

          <template #subname>
            {{ getShareLabels(share) }}
          </template>
          <template #actions>
            <NcActionButton @click="deleteLink(share)" :disabled="loading">
              {{ t('memories', 'Remove') }}

              <template #icon>
                <CloseIcon :size="20" />
              </template>
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
    </div>

    <XLoadingIcon v-if="loading" />

    <template #buttons>
      <NcButton class="primary" :disabled="loading" @click="createLink">
        {{ t('memories', 'Create Link') }}
      </NcButton>
      <NcButton class="primary" :disabled="loading" @click="refreshUrls">
        {{ t('memories', 'Refresh') }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@nextcloud/dialogs';

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js';
const NcListItem = () => import('@nextcloud/vue/dist/Components/NcListItem.js');
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js';

import UserConfig from '@mixins/UserConfig';

import Modal from './Modal.vue';
import ModalMixin from './ModalMixin';

import { API } from '@services/API';
import * as utils from '@services/utils';
import * as nativex from '@native';

import CloseIcon from 'vue-material-design-icons/Close.vue';
import LinkIcon from 'vue-material-design-icons/LinkVariant.vue';

type IShare = {
  id: string;
  label: string;
  token: string;
  url: string;
  hasPassword: boolean;
  expiration: number | null;
  editable: number;
};

export default defineComponent({
  name: 'NodeShareModal',
  components: {
    Modal,
    NcButton,
    NcListItem,
    NcActionButton,

    CloseIcon,
    LinkIcon,
  },

  mixins: [UserConfig, ModalMixin],

  emits: [],

  data: () => ({
    filename: '',
    loading: false,
    shares: [] as IShare[],
  }),

  computed: {
    isRoot(): boolean {
      return this.filename === '/' || this.filename === '';
    },

    sidebar() {
      return !this.isRoot && !utils.isMobile() ? this.filename : null;
    },
  },

  created() {
    console.assert(!_m.modals.shareNodeLink, 'NodeShareModal created twice');
    _m.modals.shareNodeLink = this.open;
  },

  methods: {
    async open(path: string, immediate?: boolean) {
      this.filename = path;
      this.show = true;
      this.shares = [];
      _m.sidebar.setTab('sharing');

      // Get current shares
      await this.refreshUrls();

      // Immediate sharing
      // If an existing share is found, just share it directly if it's
      // not password protected. Otherwise create a new share.
      if (immediate) {
        // create a new share if none exists
        if (this.shares.length === 0) {
          await this.createLink();
        } else {
          // find share with no password
          const share = this.shares.find((s) => !s.hasPassword);
          if (share) this.shareOrCopy(share.url);
        }
      }
    },

    async shareOrCopy(url: string) {
      if (nativex.has()) {
        return await nativex.shareUrl(url);
      }

      await this.copy(url);
      await window.navigator?.share?.({ title: this.filename, url: url });
    },

    cleanup() {
      this.show = false;
    },

    async refreshUrls() {
      this.loading = true;
      try {
        this.shares = (await axios.get(API.Q(API.SHARE_LINKS(), { path: this.filename }))).data;
      } catch (e) {
        this.shares = [];
      } finally {
        this.loading = false;
      }
    },

    getShareLabels(share: IShare): string {
      const labels: string[] = [];
      if (share.hasPassword) {
        labels.push(this.t('memories', 'Password protected'));
      }

      if (share.expiration) {
        const exp = utils.getLongDateStr(new Date(share.expiration * 1000));
        const kw = this.t('memories', 'Expires');
        labels.push(`${kw} ${exp}`);
      }

      if (share.editable) {
        labels.push(this.t('memories', 'Editable'));
      }

      if (labels.length > 0) {
        return `${labels.join(', ')}`;
      }

      return this.t('memories', 'Read only');
    },

    async createLink(): Promise<IShare> {
      this.loading = true;
      try {
        const res = await axios.post<IShare>(API.SHARE_NODE(), {
          path: this.filename,
        });
        const share = res.data;
        this.shares.push(share);
        this.refreshSidebar();
        this.shareOrCopy(share.url);
        return share;
      } finally {
        this.loading = false;
      }
    },

    async deleteLink(share: IShare) {
      this.loading = true;
      try {
        await axios.post(API.SHARE_DELETE(), { id: share.id });
      } finally {
        this.loading = false;
      }
      this.refreshUrls();
      this.refreshSidebar();
    },

    async copy(url: string) {
      try {
        await window.navigator.clipboard.writeText(url);
        showSuccess(this.t('memories', 'Link copied to clipboard'));
      } catch (e) {
        showError(this.t('memories', 'Failed to copy link to clipboard'));
      }
    },

    refreshSidebar() {
      if (utils.isMobile()) return;
      _m.sidebar.close();
      _m.sidebar.open(0, this.filename, true);
    },
  },
});
</script>

<style lang="scss" scoped>
.links {
  margin-top: 1em;

  :deep .avatar {
    padding: 0 0.5em;
  }
}
</style>
