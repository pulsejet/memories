<template>
  <div class="admin-section admin-help">
    <h2>{{ $options.title }}</h2>

    <span class="thanks">
      {{ t('memories', 'Thank you for choosing Nextcloud and Memories to store your precious data!') }}
    </span>
    <br /><br />

    {{ t('memories', 'Memories is very feature rich and setting it up properly can take some time.') }}
    <br />
    {{ t('memories', 'If you just installed Memories, make sure you read the getting started guide:') }}
    <a target="_blank" href="https://memories.gallery/install/">
      {{ t('memories', 'External Link') }}
    </a>
    <br />
    {{ t('memories', 'In case you run into any issues or bugs, you can get help through several channels.') }}
    <br />

    <ul>
      <li>
        <a href="https://discord.gg/7Dr9f9vNjJ" target="_blank">Discord community</a>
        (any questions, feedback, suggestions, etc.)
      </li>
      <li>
        <a href="https://github.com/pulsejet/memories/issues" target="_blank">GitHub issues</a>
        (bugs and feature requests)
      </li>
    </ul>

    {{ t('memories', 'Memories is a completely free and open source app under active development.') }}
    <br />
    {{ t('memories', 'You can contribute in several ways. See the project page for more details:') }}
    <a target="_blank" href="https://github.com/pulsejet/memories#-support-the-project">
      {{ t('memories', 'External Link') }}
    </a>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from 'services/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Support',
  title: t('memories', 'Help & Support'),
  mixins: [AdminMixin],
});
</script>

<style lang="scss" scoped>
.admin-help {
  .thanks {
    font-weight: 500;
  }
  ul {
    line-height: 1.8em;
    margin-top: 0.3em;
    margin-bottom: 0.3em;
    margin-left: 1.5em;
  }
}
</style>
