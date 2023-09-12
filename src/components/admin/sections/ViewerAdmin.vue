<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    {{ t('memories', 'Default high resolution image loading behavior of the photo viewer.') }}
    {{ t('memories', 'The configuration here also applies to public link shares.') }}
    {{ t('memories', 'Users may override this setting.') }}
    <br />

    <NcCheckboxRadioSwitch
      :checked.sync="config['memories.viewer.high_res_cond_default']"
      value="zoom"
      name="vhrc_radio"
      type="radio"
      @update:checked="update('memories.viewer.high_res_cond_default')"
      >{{ t('memories', 'Load high resolution image on zoom') }}
    </NcCheckboxRadioSwitch>
    <NcCheckboxRadioSwitch
      :checked.sync="config['memories.viewer.high_res_cond_default']"
      value="always"
      name="vhrc_radio"
      type="radio"
      @update:checked="update('memories.viewer.high_res_cond_default')"
      >{{ t('memories', 'Always load high resolution image (not recommended if using HEIC/TIFF)') }}
    </NcCheckboxRadioSwitch>
    <NcCheckboxRadioSwitch
      :checked.sync="config['memories.viewer.high_res_cond_default']"
      value="never"
      name="vhrc_radio"
      type="radio"
      @update:checked="update('memories.viewer.high_res_cond_default')"
      >{{ t('memories', 'Never load high resolution image') }}
    </NcCheckboxRadioSwitch>
  </div>
</template>

<script lang="ts">
import { defineComponent } from 'vue';

import { translate as t } from '@nextcloud/l10n';

import AdminMixin from '../AdminMixin';

export default defineComponent({
  name: 'Viewer',
  title: t('memories', 'Photo Viewer'),
  mixins: [AdminMixin],
});
</script>
