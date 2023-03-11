<template>
  <Modal
    @close="close"
    size="normal"
    v-if="show"
    :sidebar="!isRoot ? this.filename : null"
  >
    <template #title>
      {{ t("memories", "Link Sharing") }}
    </template>

    <div v-if="isRoot">
      {{ t("memories", "You cannot share the root folder") }}
    </div>
    <div v-else>
      {{
        t(
          "memories",
          "Public link shares are available to people outside Nextcloud."
        )
      }}
      <br />
      {{
        t(
          "memories",
          "You may create or update permissions on public links using the sidebar."
        )
      }}
      <br />
      {{ t("memories", "Click a link to copy to clipboard.") }}
    </div>

    <div class="links">
      <ul>
        <NcListItem
          v-for="share of shares"
          :title="share.label || t('memories', 'Share link')"
          :key="share.id"
          :bold="false"
          :href="share.url"
          :compact="true"
          @click.prevent="copy(share.url)"
        >
          <template #icon>
            <LinkIcon class="avatar" :size="20" />
          </template>
          <template #subtitle>
            {{ getShareLabels(share) }}
          </template>
          <template #actions>
            <NcActionButton @click="deleteLink(share)" :disabled="loading">
              {{ t("memories", "Remove") }}

              <template #icon>
                <CloseIcon :size="20" />
              </template>
            </NcActionButton>
          </template>
        </NcListItem>
      </ul>
    </div>

    <NcLoadingIcon v-if="loading" />

    <template #buttons>
      <NcButton class="primary" :disabled="loading" @click="createLink">
        {{ t("memories", "Create Link") }}
      </NcButton>
      <NcButton class="primary" :disabled="loading" @click="refreshUrls">
        {{ t("memories", "Refresh") }}
      </NcButton>
    </template>
  </Modal>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import axios from "@nextcloud/axios";
import { showSuccess } from "@nextcloud/dialogs";

import UserConfig from "../../mixins/UserConfig";
import NcButton from "@nextcloud/vue/dist/Components/NcButton";
import NcLoadingIcon from "@nextcloud/vue/dist/Components/NcLoadingIcon";
const NcListItem = () => import("@nextcloud/vue/dist/Components/NcListItem");
import NcActionButton from "@nextcloud/vue/dist/Components/NcActionButton";

import * as utils from "../../services/Utils";
import Modal from "./Modal.vue";

import { API } from "../../services/API";

import CloseIcon from "vue-material-design-icons/Close.vue";
import LinkIcon from "vue-material-design-icons/LinkVariant.vue";

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
  name: "NodeShareModal",
  components: {
    Modal,
    NcButton,
    NcLoadingIcon,
    NcListItem,
    NcActionButton,

    CloseIcon,
    LinkIcon,
  },

  mixins: [UserConfig],

  data: () => ({
    show: false,
    filename: "",
    loading: false,
    shares: [] as IShare[],
  }),

  computed: {
    isRoot(): boolean {
      return this.filename === "/" || this.filename === "";
    },
  },

  created() {
    globalThis.shareNodeLink = this.open;
  },

  methods: {
    async open(path: string, immediate?: boolean) {
      this.filename = path;
      this.show = true;
      this.shares = [];
      globalThis.mSidebar.setTab("sharing");

      // Get current shares
      await this.refreshUrls();

      // Immediate sharing
      // If an existing share is found, just share it directly if it's
      // not password protected. Otherwise create a new share.
      if (immediate) {
        let share =
          this.shares.find((s) => !s.hasPassword) ||
          (await this.createLink(false));

        if (share) {
          if ("share" in window.navigator) {
            window.navigator.share({
              title: this.filename,
              url: share.url,
            });
          } else {
            this.copy(share.url);
          }
        }
      }
    },

    close() {
      this.show = false;
      this.$emit("close");
    },

    async refreshUrls() {
      this.loading = true;
      try {
        this.shares = (
          await axios.get(API.Q(API.SHARE_LINKS(), { path: this.filename }))
        ).data;
      } catch (e) {
        this.shares = [];
      } finally {
        this.loading = false;
      }
    },

    getShareLabels(share: IShare): string {
      const labels = [];
      if (share.hasPassword) {
        labels.push(this.t("memories", "Password protected"));
      }

      if (share.expiration) {
        const exp = utils.getLongDateStr(new Date(share.expiration * 1000));
        const kw = this.t("memories", "Expires");
        labels.push(`${kw} ${exp}`);
      }

      if (share.editable) {
        labels.push(this.t("memories", "Editable"));
      }

      if (labels.length > 0) {
        return `${labels.join(", ")}`;
      }

      return this.t("memories", "Read only");
    },

    async createLink(copy = true): Promise<IShare> {
      this.loading = true;
      try {
        const res = await axios.post<IShare>(API.SHARE_NODE(), {
          path: this.filename,
        });
        const share = res.data;
        this.shares.push(share);
        this.refreshSidebar();

        if (copy) {
          this.copy(share.url);
        }

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

    copy(url: string) {
      window.navigator.clipboard.writeText(url);
      showSuccess(this.t("memories", "Link copied to clipboard"));
    },

    refreshSidebar() {
      globalThis.mSidebar.close();
      globalThis.mSidebar.open({ filename: this.filename } as any);
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
