<template>
  <div class="admin-section">
    <h2>{{ $options.title }}</h2>

    {{ t('memories', 'Default high resolution image loading behavior of the photo viewer.') }}
    {{ t('memories', 'The configuration here also applies to public link shares.') }}
    {{ t('memories', 'Users may override this setting.') }}
    <br />

    <NcCheckboxRadioSwitch
      v-model="systemConfig['memories.viewer.high_res_cond_default']"
      value="zoom"
      name="vhrc_radio"
      type="radio"
      @update:model-value="update('memories.viewer.high_res_cond_default')"
      >{{ t('memories', 'Load high resolution image on zoom') }}
    </NcCheckboxRadioSwitch>
    <NcCheckboxRadioSwitch
      v-model="systemConfig['memories.viewer.high_res_cond_default']"
      value="always"
      name="vhrc_radio"
      type="radio"
      @update:model-value="update('memories.viewer.high_res_cond_default')"
      >{{ t('memories', 'Always load high resolution image (not recommended if using HEIC/TIFF)') }}
    </NcCheckboxRadioSwitch>
    <NcCheckboxRadioSwitch
      v-model="systemConfig['memories.viewer.high_res_cond_default']"
      value="never"
      name="vhrc_radio"
      type="radio"
      @update:model-value="update('memories.viewer.high_res_cond_default')"
      >{{ t('memories', 'Never load high resolution image') }}
    </NcCheckboxRadioSwitch>

    <br />
    {{ t('memories', 'Default video autoplay behavior of the photo viewer.') }}
    {{ t('memories', 'Users may override this setting unless disallowed.') }}
    <br />

    <NcCheckboxRadioSwitch
      v-model="systemConfig['memories.viewer.video.autoplay']"
      value="true"
      name="vauto_radio"
      type="radio"
      @update:model-value="update('memories.viewer.video.autoplay')"
      >{{ t('memories', 'Autoplay videos') }}
    </NcCheckboxRadioSwitch>
    <NcCheckboxRadioSwitch
      v-model="systemConfig['memories.viewer.video.autoplay']"
      value="false"
      name="vauto_radio"
      type="radio"
      @update:model-value="update('memories.viewer.video.autoplay')"
      >{{ t('memories', 'Do not autoplay videos') }}
    </NcCheckboxRadioSwitch>
    <NcCheckboxRadioSwitch
      v-model="systemConfig['memories.viewer.video.autoplay']"
      value="disallow"
      name="vauto_radio"
      type="radio"
      @update:model-value="update('memories.viewer.video.autoplay')"
      >{{ t('memories', 'Disallow video autoplay') }}
    </NcCheckboxRadioSwitch>
  </div>
</template>

<script setup lang="ts">
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';

import { t } from '@services/l10n';

import { adminSectionProps, useAdminSection, type AdminSectionEmits } from '../useAdminSection';

defineOptions({
  name: 'Viewer',
  title: t('memories', 'Photo Viewer'),
});

const props = defineProps(adminSectionProps);
const emit = defineEmits<AdminSectionEmits>();

const { update } = useAdminSection(props, emit);
</script>
