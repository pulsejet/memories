<template>
  <div class="admin-section">
    <h2>{{ t('memories', 'Media Indexing') }}</h2>

    <template v-if="status">
      <NcNoteCard :type="status.indexed_count > 0 ? 'success' : 'warning'">
        {{
          t('memories', '{n} media files have been indexed', {
            n: status.indexed_count,
          })
        }}
      </NcNoteCard>
      <NcNoteCard :type="status.last_index_job_status_type">
        {{
          t('memories', 'Automatic Indexing status: {status}', {
            status: status.last_index_job_status,
          })
        }}
      </NcNoteCard>
      <NcNoteCard v-if="status.last_index_job_start" :type="status.last_index_job_duration ? 'success' : 'warning'">
        {{
          t('memories', 'Last index job was run {t} seconds ago.', {
            t: status.last_index_job_start,
          })
        }}
        {{
          status.last_index_job_duration
            ? t('memories', 'It took {t} seconds to complete.', {
                t: status.last_index_job_duration,
              })
            : t('memories', 'It is still running or was interrupted.')
        }}
      </NcNoteCard>
      <NcNoteCard type="error" v-if="status.bad_encryption">
        {{
          t(
            'memories',
            'Only server-side encryption (OC_DEFAULT_MODULE) is supported, but another encryption module is enabled.'
          )
        }}
      </NcNoteCard>
    </template>

    <p>
      {{
        t(
          'memories',
          'The EXIF indexes are built and checked in a periodic background task. Be careful when selecting anything other than automatic indexing. For example, setting the indexing to only timeline folders may cause delays before media becomes available to users, since the user configures the timeline only after logging in.'
        )
      }}
      {{ t('memories', 'Folders with a ".nomedia" file are always excluded from indexing.') }}
      <NcCheckboxRadioSwitch
        :checked.sync="config['memories.index.mode']"
        value="1"
        name="idxm_radio"
        type="radio"
        @update:checked="update('memories.index.mode')"
        >{{ t('memories', 'Index all media automatically (recommended)') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="config['memories.index.mode']"
        value="2"
        name="idxm_radio"
        type="radio"
        @update:checked="update('memories.index.mode')"
        >{{ t('memories', 'Index per-user timeline folders (not recommended)') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="config['memories.index.mode']"
        value="3"
        name="idxm_radio"
        type="radio"
        @update:checked="update('memories.index.mode')"
        >{{ t('memories', 'Index a fixed relative path') }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="config['memories.index.mode']"
        value="0"
        name="idxm_radio"
        type="radio"
        @update:checked="update('memories.index.mode')"
        >{{ t('memories', 'Disable background indexing') }}
      </NcCheckboxRadioSwitch>

      <NcTextField
        :label="t('memories', 'Indexing path (relative, all users)')"
        :label-visible="true"
        :value="config['memories.index.path']"
        @change="update('memories.index.path', $event.target.value)"
        v-if="config['memories.index.mode'] === '3'"
      />
    </p>

    {{ t('memories', 'For advanced usage, perform a run of indexing by running:') }}
    <br />
    <code>occ memories:index</code>
    <br />
    {{ t('memories', 'Run index in parallel with 4 threads:') }}
    <br />
    <code>bash -c 'for i in {1..4}; do (occ memories:index &amp;); done'</code>
    <br />
    {{ t('memories', 'Force re-indexing of all files:') }}
    <br />
    <code>occ memories:index --force</code>
    <br />
    {{ t('memories', 'You can limit indexing by user and/or folder:') }}
    <br />
    <code>occ memories:index --user=admin --folder=/Photos/</code>
    <br />
    {{ t('memories', 'Clear all existing index tables:') }}
    <br />
    <code>occ memories:index --clear</code>
    <br />

    <br />
    {{ t('memories', 'The following MIME types are configured for preview generation correctly. More documentation:') }}
    <a href="https://pulsejet.github.io/memories/file-types/" target="_blank">
      {{ t('memories', 'External Link') }}
    </a>
    <br />
    <code v-if="status"
      ><template v-for="mime in status.mimes">{{ mime }}<br :key="mime" /></template
    ></code>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Indexing',
  mixins: [AdminMixin],
});
</script>
