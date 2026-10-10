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
            <NcActionButton @click="deleteLink(share)" :disabled="!!loading">
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
      <div class="button-grid">
        <NcButton class="primary" :disabled="!!loading" @click="createLink">
          {{ t('memories', 'Create Link') }}
        </NcButton>
        <NcButton class="primary" :disabled="!!loading" @click="refreshUrls">
          {{ t('memories', 'Refresh') }}
        </NcButton>
      </div>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { computed, ref, useTemplateRef, defineAsyncComponent } from 'vue';
import { useClipboard } from '@vueuse/core';

import axios from '@nextcloud/axios';
import { showError, showSuccess } from '@services/utils/dialog';

import NcButton from '@nextcloud/vue/components/NcButton';
import NcActionButton from '@nextcloud/vue/components/NcActionButton';
const NcListItem = defineAsyncComponent(() => import('@nextcloud/vue/components/NcListItem'));

import Modal from '@components/modal/Modal.vue';
import XLoadingIcon from '@components/XLoadingIcon.vue';

import { useModal } from '@services/modal';
import { t } from '@services/l10n';
import { windowDims } from '@services/viewport';
import { API } from '@services/API';
import { isAbortError, useAbort } from '@services/utils/abort-vue';
import * as utils from '@services/utils/common';
import * as nativex from '@native';

import type { IShare } from '@typings';

import CloseIcon from 'vue-material-design-icons/Close.vue';
import LinkIcon from 'vue-material-design-icons/LinkVariant.vue';

defineOptions({
  name: 'NodeShareModal',
});

const modal = useTemplateRef('modal');
const { show } = useModal(modal);

const filename = ref('');
const loading = ref(0);
const shares = ref<IShare[]>([]);

const abort = useAbort();
const { copy: copyText } = useClipboard({ legacy: true });

const isRoot = computed(() => filename.value === '/' || filename.value === '');
const sidebar = computed(() => (!isRoot.value && !windowDims.isMobile ? filename.value : null));

console.assert(!_m.modals.shareNodeLink, 'NodeShareModal created twice');
_m.modals.shareNodeLink = open;

async function open(path: string, immediate?: boolean) {
  filename.value = path;
  show.value = true;
  shares.value = [];
  _m.sidebar.setTab('sharing');

  // Get current shares
  await refreshUrls();

  // Immediate sharing
  // If an existing share is found, just share it directly if it's
  // not password protected. Otherwise create a new share.
  if (immediate) {
    // create a new share if none exists
    if (shares.value.length === 0) {
      await createLink();
    } else {
      // find share with no password
      const share = shares.value.find((s) => !s.hasPassword);
      if (share) shareOrCopy(share.url);
    }
  }
}

async function shareOrCopy(url: string) {
  if (nativex.has()) {
    return await nativex.shareUrl(url);
  }

  await copy(url);

  try {
    await window.navigator?.share?.({ title: filename.value, url: url });
  } catch (e) {
    if (isAbortError(e)) return;
    console.error(e);
  }
}

function cleanup() {
  abort.abort();
  show.value = false;
}

async function refreshUrls() {
  const signal = abort.renew();
  loading.value++;
  try {
    shares.value = (await axios.get(API.Q(API.SHARE_LINKS(), { path: filename.value }), { signal })).data;
    signal.throwIfAborted();
  } catch (e) {
    if (isAbortError(e)) return;
    shares.value = [];
  } finally {
    loading.value--;
  }
}

function getShareLabels(share: IShare): string {
  const labels: string[] = [];
  if (share.hasPassword) {
    labels.push(t('memories', 'Password protected'));
  }

  if (share.expiration) {
    const exp = utils.getLongDateStr(new Date(share.expiration * 1000));
    const kw = t('memories', 'Expires');
    labels.push(`${kw} ${exp}`);
  }

  if (share.editable) {
    labels.push(t('memories', 'Editable'));
  }

  if (labels.length > 0) {
    return `${labels.join(', ')}`;
  }

  return t('memories', 'Read only');
}

async function createLink(): Promise<IShare | null> {
  const signal = abort.renew();
  loading.value++;
  try {
    const res = await axios.post<IShare>(API.SHARE_NODE(), { path: filename.value }, { signal });
    const newShare = res.data;
    signal.throwIfAborted();
    shares.value.push(newShare);
    refreshSidebar();
    shareOrCopy(newShare.url);
    return newShare;
  } catch (e) {
    if (isAbortError(e)) return null;
    console.error(e);
    showError(t('memories', 'Failed to create share link'));
    return null;
  } finally {
    loading.value--;
  }
}

async function deleteLink(share: IShare) {
  const signal = abort.renew();
  loading.value++;
  try {
    await axios.post(API.SHARE_DELETE(), { id: share.id }, { signal });
    signal.throwIfAborted();
  } catch (e) {
    if (isAbortError(e)) return;
    console.error(e);
    showError(t('memories', 'Failed to delete share link'));
    return;
  } finally {
    loading.value--;
  }
  refreshUrls();
  refreshSidebar();
}

async function copy(url: string) {
  try {
    await copyText(url);
    showSuccess(t('memories', 'Link copied to clipboard'));
  } catch (e) {
    if (isAbortError(e)) return;
    showError(t('memories', 'Failed to copy link to clipboard'));
  }
}

function refreshSidebar() {
  if (windowDims.isMobile) return;
  _m.sidebar.close();
  _m.sidebar.open(0, filename.value, true);
}
</script>

<style lang="scss" scoped>
.links {
  margin-top: 1em;

  :deep(.avatar) {
    padding: 0 0.5em;
  }
}
div.button-grid {
  display: flex;
  gap: 1em;
}
</style>
