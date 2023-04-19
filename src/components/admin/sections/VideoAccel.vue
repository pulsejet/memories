<template>
  <div class="admin-section">
    <h3>{{ t("memories", "Hardware Acceleration") }}</h3>

    <p>
      {{
        t(
          "memories",
          "You must first make sure the correct drivers are installed before configuring acceleration."
        )
      }}
      <br />
      {{
        t(
          "memories",
          "Make sure you test hardware acceleration with various options after enabling."
        )
      }}
      <br />
      {{
        t(
          "memories",
          "Do not enable multiple types of hardware acceleration simultaneously."
        )
      }}

      <br />
      <br />

      {{
        t(
          "memories",
          "Intel processors supporting QuickSync Video (QSV) as well as some AMD GPUs can be used for transcoding using VA-API acceleration."
        )
      }}
      {{
        t(
          "memories",
          "For more details on driver installation, check the documentation:"
        )
      }}
      <a
        target="_blank"
        href="https://github.com/pulsejet/memories/wiki/HW-Transcoding#va-api"
      >
        {{ t("memories", "External Link") }}
      </a>

      <NcNoteCard :type="vaapiStatusType" v-if="status">
        {{ vaapiStatusText }}
      </NcNoteCard>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.vod.vaapi']"
        @update:checked="update('memories.vod.vaapi')"
        type="switch"
      >
        {{ t("memories", "Enable acceleration with VA-API") }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.vaapi']"
        :checked.sync="config['memories.vod.vaapi.low_power']"
        @update:checked="update('memories.vod.vaapi.low_power')"
        type="switch"
      >
        {{ t("memories", "Enable low-power mode (QSV)") }}
      </NcCheckboxRadioSwitch>

      {{
        t(
          "memories",
          "NVIDIA GPUs can be used for transcoding using the NVENC encoder with the proper drivers."
        )
      }}
      <br />
      {{
        t(
          "memories",
          "Depending on the versions of the installed SDK and ffmpeg, you need to specify the scaler to use"
        )
      }}

      <NcNoteCard type="warning">
        {{
          t(
            "memories",
            "No automated tests are available for NVIDIA acceleration."
          )
        }}
      </NcNoteCard>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="config['memories.vod.nvenc']"
        @update:checked="update('memories.vod.nvenc')"
        type="switch"
      >
        {{ t("memories", "Enable acceleration with NVENC") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.nvenc']"
        :checked.sync="config['memories.vod.nvenc.temporal_aq']"
        @update:checked="update('memories.vod.nvenc.temporal_aq')"
        type="switch"
      >
        {{ t("memories", "Enable NVENC Temporal AQ") }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.nvenc']"
        :checked.sync="config['memories.vod.nvenc.scale']"
        value="npp"
        name="nvence_scaler_radio"
        type="radio"
        @update:checked="update('memories.vod.nvenc.scale')"
        class="m-radio"
        >{{ t("memories", "NPP scaler") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !config['memories.vod.nvenc']"
        :checked.sync="config['memories.vod.nvenc.scale']"
        value="cuda"
        name="nvence_scaler_radio"
        type="radio"
        class="m-radio"
        @update:checked="update('memories.vod.nvenc.scale')"
        >{{ t("memories", "CUDA scaler") }}
      </NcCheckboxRadioSwitch>
    </p>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import AdminMixin from "../AdminMixin";

export default defineComponent({
  name: "VideoAccel",
  mixins: [AdminMixin],

  computed: {
    vaapiStatusText(): string {
      if (!this.status) return "";

      const dev = "/dev/dri/renderD128";
      if (this.status.vaapi_dev === "ok") {
        return this.t("memories", "VA-API device ({dev}) is readable", { dev });
      } else if (this.status.vaapi_dev === "not_found") {
        return this.t("memories", "VA-API device ({dev}) not found", { dev });
      } else if (this.status.vaapi_dev === "not_readable") {
        return this.t(
          "memories",
          "VA-API device ({dev}) has incorrect permissions",
          { dev }
        );
      } else {
        return this.t("memories", "VA-API device status: {status}", {
          status: this.status.vaapi_dev,
        });
      }
    },

    vaapiStatusType(): string {
      return this.status?.vaapi_dev === "ok" ? "success" : "error";
    },
  },
});
</script>
