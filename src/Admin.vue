<template>
  <div class="outer" v-if="loaded">
    <NcLoadingIcon class="loading-icon" v-show="loading" />

    <!----------------------------- General Settings ----------------------------->
    <h2>{{ t("memories", "EXIF Extraction") }}</h2>

    <template v-if="status">
      <NcNoteCard :type="binaryStatusType(status.exiftool)">
        {{ binaryStatus("exiftool", status.exiftool) }}
      </NcNoteCard>
    </template>

    <NcTextField
      :label="t('memories', 'Path to packaged exiftool binary')"
      :label-visible="true"
      :value="exiftoolPath"
      @change="update('exiftoolPath', $event.target.value)"
      disabled
    />

    <template v-if="status">
      <NcNoteCard :type="binaryStatusType(status.perl, false)">
        {{ binaryStatus("perl", status.perl) }}
      </NcNoteCard>
    </template>

    <NcCheckboxRadioSwitch
      :checked.sync="exiftoolPerl"
      @update:checked="update('exiftoolPerl')"
      type="switch"
    >
      {{
        t("memories", "Use system perl (only if packaged binary does not work)")
      }}
    </NcCheckboxRadioSwitch>

    <!----------------------------- Places ----------------------------->
    <h2>{{ t("memories", "Reverse Geocoding") }}</h2>

    <p>
      <template v-if="status">
        <NcNoteCard :type="gisStatusType">
          {{ gisStatus }}
        </NcNoteCard>
        <NcNoteCard
          v-if="status.gis_count !== undefined"
          :type="status.gis_count > 0 ? 'success' : 'warning'"
        >
          {{
            status.gis_count > 0
              ? t("memories", "Database is populated with {n} geometries", {
                  n: status.gis_count,
                })
              : t("memories", "Geometry table has not been created")
          }}
        </NcNoteCard>
      </template>

      {{
        t(
          "memories",
          "Memories supports offline reverse geocoding using the OpenStreetMaps data on MySQL and Postgres."
        )
      }}
      <br />
      {{
        t(
          "memories",
          "You need to download the planet data into your database. This is highly recommended and has low overhead."
        )
      }}
      <br />
      {{
        t(
          "memories",
          "If the button below does not work for importing the planet data, use 'occ memories:places-setup'."
        )
      }}
      <br />
      {{
        t(
          "memories",
          "Note: the geometry data is stored in the 'memories_planet_geometry' table, with no prefix."
        )
      }}
    </p>

    <form
      :action="placesSetupUrl"
      method="post"
      @submit="placesSetup"
      target="_blank"
    >
      <input name="requesttoken" type="hidden" :value="requestToken" />
      <NcButton nativeType="submit" type="warning">
        {{ t("memories", "Download planet database") }}
      </NcButton>
    </form>

    <!----------------------------- Video Streaming ----------------------------->
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
          "Note that this may be very CPU intensive without hardware acceleration, and transcoding will not be used for external storage."
        )
      }}

      <NcCheckboxRadioSwitch
        :checked.sync="enableTranscoding"
        @update:checked="update('enableTranscoding')"
        type="switch"
      >
        {{ t("memories", "Enable Transcoding") }}
      </NcCheckboxRadioSwitch>

      <template v-if="status">
        <NcNoteCard :type="binaryStatusType(status.ffmpeg)">
          {{ binaryStatus("ffmpeg", status.ffmpeg) }}
        </NcNoteCard>
        <NcNoteCard :type="binaryStatusType(status.ffprobe)">
          {{ binaryStatus("ffprobe", status.ffprobe) }}
        </NcNoteCard>
      </template>

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

      <template v-if="status">
        <NcNoteCard :type="binaryStatusType(status.govod)">
          {{ binaryStatus("go-vod", status.govod) }}
        </NcNoteCard>
      </template>

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

      <NcNoteCard :type="vaapiStatusType" v-if="status">
        {{ vaapiStatusText }}
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
import NcLoadingIcon from "@nextcloud/vue/dist/Components/NcLoadingIcon";
import NcButton from "@nextcloud/vue/dist/Components/NcButton";

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

type BinaryStatus = "ok" | "not_found" | "not_executable" | "test_ok" | string;

type IStatus = {
  gis_type: number;
  gis_count?: number;
  exiftool: BinaryStatus;
  perl: BinaryStatus;
  ffmpeg: BinaryStatus;
  ffprobe: BinaryStatus;
  govod: BinaryStatus;
  vaapi_dev: "ok" | "not_found" | "not_readable";
};

export default defineComponent({
  name: "Admin",
  components: {
    NcCheckboxRadioSwitch,
    NcNoteCard,
    NcTextField,
    NcLoadingIcon,
    NcButton,
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

    loading: 0,

    status: null as IStatus,
  }),

  mounted() {
    this.reload();
    this.refreshStatus();
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

    async refreshStatus() {
      try {
        this.loading++;
        const res = await axios.get(API.SYSTEM_STATUS());
        this.status = res.data;
      } finally {
        this.loading--;
      }
    },

    async update(key: string, value = null) {
      value = value ?? this[key];
      const setting = settings[key];

      this[key] = value;

      // Inversion
      if (invertedBooleans.includes(key)) {
        value = !value;
      }

      this.loading++;
      axios
        .put(API.SYSTEM_CONFIG(setting), {
          value: value,
        })
        .catch((err) => {
          console.error(err);
          showError(this.t("memories", "Failed to update setting"));
        })
        .finally(() => {
          this.loading--;

          if (this["refreshTimer"]) {
            clearTimeout(this["refreshTimer"]);
          }
          this["refreshTimer"] = setTimeout(() => {
            this.refreshStatus();
            delete this["refreshTimer"];
          }, 500);
        });
    },

    placesSetup(event: Event) {
      const msg =
        "Looks like the database is already setup. Are you sure you want to drop the table and redownload OSM data?";
      if (this.status.gis_count && !confirm(msg)) {
        event.preventDefault();
        event.stopPropagation();
        return;
      } else {
        alert(
          "Please wait for the download and insertion to complete. This may take a while."
        );
      }
    },

    binaryStatus(name: string, status: BinaryStatus): string {
      if (status === "ok") {
        return this.t("memories", "{name} binary exists and is executable", {
          name,
        });
      } else if (status === "not_found") {
        return this.t("memories", "{name} binary not found", { name });
      } else if (status === "not_executable") {
        return this.t("memories", "{name} binary is not executable", {
          name,
        });
      } else if (status.startsWith("test_fail")) {
        return this.t(
          "memories",
          "{name} failed test: {info}",
          {
            name,
            info: status.substring(10),
          },
          0,
          {
            escape: false,
            sanitize: false,
          }
        );
      } else if (status === "test_ok") {
        return this.t("memories", "{name} binary exists and is usable", {
          name,
        });
      } else {
        return this.t("memories", "{name} binary status: {status}", {
          name,
          status,
        });
      }
    },

    binaryStatusType(status: BinaryStatus, critical = true): string {
      if (status === "ok" || status === "test_ok") {
        return "success";
      } else if (
        status === "not_found" ||
        status === "not_executable" ||
        status.startsWith("test_fail")
      ) {
        return critical ? "error" : "warning";
      } else {
        return "warning";
      }
    },
  },

  computed: {
    requestToken() {
      return (<any>axios.defaults.headers).requesttoken;
    },

    gisStatus() {
      if (typeof this.status.gis_type !== "number") {
        return this.status.gis_type;
      }

      if (this.status.gis_type <= 0) {
        return this.t(
          "memories",
          "Geometry support was not detected in your database"
        );
      } else if (this.status.gis_type === 1) {
        return this.t("memories", "MySQL-like geometry support was detected ");
      } else if (this.status.gis_type === 2) {
        return this.t(
          "memories",
          "Postgres native geometry support was detected"
        );
      }
    },

    gisStatusType() {
      return typeof this.status.gis_type !== "number" ||
        this.status.gis_type <= 0
        ? "error"
        : "success";
    },

    placesSetupUrl() {
      return API.OCC_PLACES_SETUP();
    },

    vaapiStatusText(): string {
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
      return this.status.vaapi_dev === "ok" ? "success" : "error";
    },
  },
});
</script>

<style lang="scss" scoped>
.outer {
  padding: 20px;
  padding-top: 0px;

  .loading-icon {
    top: 10px;
    right: 20px;
    position: absolute;
    width: 28px;
    height: 28px;

    :deep svg {
      width: 100%;
      height: 100%;
    }
  }

  form {
    margin-top: 1em;
  }

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
