<template>
  <div class="outer" v-if="loaded">
    <h2>{{ t("memories", "EXIF Extraction") }}</h2>

    <NcTextField
      :label="t('memories', 'Path to packaged exiftool binary')"
      :label-visible="true"
      :value="exiftoolPath"
      @change="update('exiftoolPath', $event.target.value)"
      disabled
    />

    <NcCheckboxRadioSwitch
      :checked.sync="exiftoolPerl"
      @update:checked="update('exiftoolPerl')"
      type="switch"
    >
      {{
        t("memories", "Use system perl (only if packaged binary does not work)")
      }}
    </NcCheckboxRadioSwitch>

    <h2>{{ t("memories", "Video Streaming") }}</h2>

    <p>
      {{
        t(
          "memories",
          "Live transcoding provides for adaptive streaming of videos using HLS."
        )
      }}
      <br />
      {{
        t(
          "memories",
          "Note that this may be very CPU intensive without hardware acceleration."
        )
      }}

      <NcCheckboxRadioSwitch
        :checked.sync="enableTranscoding"
        @update:checked="update('enableTranscoding')"
        type="switch"
      >
        {{ t("memories", "Enable Transcoding") }}
      </NcCheckboxRadioSwitch>

      <NcTextField
        :label="t('memories', 'ffmpeg path')"
        :label-visible="true"
        :value="ffmpegPath"
        @change="update('ffmpegPath', $event.target.value)"
      />

      <NcTextField
        :label="t('memories', 'ffprobe path')"
        :label-visible="true"
        :value="ffprobePath"
        @change="update('ffprobePath', $event.target.value)"
      />

      <br />
      {{ t("memories", "Global default video quality (user may override)") }}
      <NcCheckboxRadioSwitch
        :checked.sync="videoDefaultQuality"
        value="0"
        name="vdq_radio"
        type="radio"
        @update:checked="update('videoDefaultQuality')"
        >{{ t("memories", "Auto (adaptive transcode)") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="videoDefaultQuality"
        value="-1"
        name="vdq_radio"
        type="radio"
        @update:checked="update('videoDefaultQuality')"
        >{{ t("memories", "Original (transcode with max quality)") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="videoDefaultQuality"
        value="-2"
        name="vdq_radio"
        type="radio"
        @update:checked="update('videoDefaultQuality')"
        >{{ t("memories", "Direct (original video file without transcode)") }}
      </NcCheckboxRadioSwitch>
    </p>

    <h3>{{ t("memories", "Transcoder configuration") }}</h3>
    <p>
      {{
        t(
          "memories",
          "Memories uses the go-vod transcoder. You can run go-vod exernally (e.g. in a separate Docker container for hardware acceleration) or use the built-in transcoder. To use an external transcoder, enable the following option and follow the instructions at this link:"
        )
      }}
      <a
        target="_blank"
        href="https://github.com/pulsejet/memories/wiki/HW-Transcoding"
      >
        {{ t("memories", "external transcoder configuration") }}
      </a>

      <NcCheckboxRadioSwitch
        :checked.sync="enableExternalTranscoder"
        @update:checked="update('enableExternalTranscoder')"
        type="switch"
      >
        {{ t("memories", "Enable external transcoder (go-vod)") }}
      </NcCheckboxRadioSwitch>

      <NcTextField
        :label="t('memories', 'Binary path (local only)')"
        :label-visible="true"
        :value="goVodPath"
        @change="update('goVodPath', $event.target.value)"
      />

      <NcTextField
        :label="t('memories', 'Bind address (local only)')"
        :label-visible="true"
        :value="goVodBind"
        @change="update('goVodBind', $event.target.value)"
      />

      <NcTextField
        :label="t('memories', 'Connection address (same as bind if local)')"
        :label-visible="true"
        :value="goVodConnect"
        @change="update('goVodConnect', $event.target.value)"
      />
    </p>

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
          "For more details on driver installation, check the following link:"
        )
      }}
      <a
        target="_blank"
        href="https://github.com/pulsejet/memories/wiki/HW-Transcoding#va-api"
      >
        VA-API configuration
      </a>

      <NcNoteCard type="warning">
        {{
          t(
            "memories",
            "/dev/dri/renderD128 is required for VA-API acceleration."
          )
        }}
      </NcNoteCard>

      <NcCheckboxRadioSwitch
        :checked.sync="enableVaapi"
        @update:checked="update('enableVaapi')"
        type="switch"
      >
        {{ t("memories", "Enable acceleration with VA-API") }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :checked.sync="enableVaapiLowPower"
        @update:checked="update('enableVaapiLowPower')"
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
        :checked.sync="enableNvenc"
        @update:checked="update('enableNvenc')"
        type="switch"
      >
        {{ t("memories", "Enable acceleration with NVENC") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="enableNvencTemporalAQ"
        @update:checked="update('enableNvencTemporalAQ')"
        type="switch"
      >
        {{ t("memories", "Enable NVENC Temporal AQ") }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :checked.sync="nvencScaler"
        value="npp"
        name="nvence_scaler_radio"
        type="radio"
        @update:checked="update('nvencScaler')"
        class="m-radio"
        >{{ t("memories", "NPP scaler") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="nvencScaler"
        value="cuda"
        name="nvence_scaler_radio"
        type="radio"
        class="m-radio"
        @update:checked="update('nvencScaler')"
        >{{ t("memories", "CUDA scaler") }}
      </NcCheckboxRadioSwitch>
    </p>
  </div>
</template>

<script lang="ts">
import { defineComponent } from "vue";

import axios from "@nextcloud/axios";
import { API } from "./services/API";
import { showError } from "@nextcloud/dialogs";

const NcCheckboxRadioSwitch = () =>
  import("@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch");
const NcNoteCard = () => import("@nextcloud/vue/dist/Components/NcNoteCard");
const NcTextField = () => import("@nextcloud/vue/dist/Components/NcTextField");

/** Map from UI to backend settings */
const settings = {
  exiftoolPath: "memories.exiftool",
  exiftoolPerl: "memories.exiftool_no_local",

  enableTranscoding: "memories.vod.disable",
  ffmpegPath: "memories.vod.ffmpeg",
  ffprobePath: "memories.vod.ffprobe",
  goVodPath: "memories.vod.path",
  goVodBind: "memories.vod.bind",
  goVodConnect: "memories.vod.connect",
  enableExternalTranscoder: "memories.vod.external",
  videoDefaultQuality: "memories.video_default_quality",

  enableVaapi: "memories.vod.vaapi",
  enableVaapiLowPower: "memories.vod.vaapi.low_power",

  enableNvenc: "memories.vod.nvenc",
  enableNvencTemporalAQ: "memories.vod.nvenc.temporal_aq",
  nvencScaler: "memories.vod.nvenc.scale",
};

/** Invert setting before saving */
const invertedBooleans = ["enableTranscoding"];

export default defineComponent({
  name: "Admin",
  components: {
    NcCheckboxRadioSwitch,
    NcNoteCard,
    NcTextField,
  },

  data: () => ({
    loaded: false,

    exiftoolPath: "",
    exiftoolPerl: false,

    enableTranscoding: false,
    ffmpegPath: "",
    ffprobePath: "",
    goVodPath: "",
    goVodBind: "",
    goVodConnect: "",
    enableExternalTranscoder: false,
    videoDefaultQuality: "",

    enableVaapi: false,
    enableVaapiLowPower: false,

    enableNvenc: false,
    enableNvencTemporalAQ: false,
    nvencScaler: "",
  }),

  mounted() {
    this.reload();
  },

  methods: {
    async reload() {
      const res = await axios.get(API.SYSTEM_CONFIG(null));
      for (const key in settings) {
        if (!res.data.hasOwnProperty(settings[key])) {
          console.error(
            `Setting ${settings[key]} not found in backend response`
          );
          continue;
        }

        this[key] = res.data[settings[key]];

        if (invertedBooleans.includes(key)) {
          this[key] = !this[key];
        }
      }

      this.loaded = true;
    },

    async update(key: string, value = null) {
      value ||= this[key];
      const setting = settings[key];

      // Inversion
      if (invertedBooleans.includes(key)) {
        value = !value;
      }

      axios
        .put(API.SYSTEM_CONFIG(setting), {
          value: value,
        })
        .catch((err) => {
          console.error(err);
          showError(this.t("memories", "Failed to update setting"));
        });
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  padding: 20px;
  padding-top: 0px;

  .checkbox-radio-switch {
    margin: 2px 8px;
  }

  .m-radio {
    display: inline-block;
  }

  h2 {
    font-size: 1.5em;
    font-weight: bold;
    margin-top: 25px;
  }

  h3 {
    font-size: 1.2em;
    font-weight: 500;
    margin-top: 20px;
  }

  a {
    color: var(--color-primary-element);
  }
}
</style>
