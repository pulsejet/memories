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

    <!----------------------------- Index Settings ----------------------------->
    <h2>{{ t("memories", "Media Indexing") }}</h2>

    <template v-if="status">
      <NcNoteCard :type="status.indexed_count > 0 ? 'success' : 'warning'">
        {{
          t("memories", "{n} media files have been indexed", {
            n: status.indexed_count,
          })
        }}
      </NcNoteCard>
      <NcNoteCard :type="status.last_index_job_status_type">
        {{
          t("memories", "Automatic Indexing status: {status}", {
            status: status.last_index_job_status,
          })
        }}
      </NcNoteCard>
      <NcNoteCard
        v-if="status.last_index_job_start"
        :type="status.last_index_job_duration ? 'success' : 'warning'"
      >
        {{
          t("memories", "Last index job was run {t} seconds ago.", {
            t: status.last_index_job_start,
          })
        }}
        {{
          status.last_index_job_duration
            ? t("memories", "It took {t} seconds to complete.", {
                t: status.last_index_job_duration,
              })
            : t("memories", "It is still running or was interrupted.")
        }}
      </NcNoteCard>
      <NcNoteCard type="error" v-if="status.bad_encryption">
        {{
          t(
            "memories",
            "Only server-side encryption (OC_DEFAULT_MODULE) is supported, but another encryption module is enabled."
          )
        }}
      </NcNoteCard>
    </template>

    <p>
      {{
        t(
          "memories",
          "The EXIF indexes are built and checked in a periodic background task. Be careful when selecting anything other than automatic indexing. For example, setting the indexing to only timeline folders may cause delays before media becomes available to users, since the user configures the timeline only after logging in."
        )
      }}
      {{
        t(
          "memories",
          'Folders with a ".nomedia" file are always excluded from indexing.'
        )
      }}
      <NcCheckboxRadioSwitch
        :checked.sync="indexingMode"
        value="1"
        name="idxm_radio"
        type="radio"
        @update:checked="update('indexingMode')"
        >{{ t("memories", "Index all media automatically (recommended)") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="indexingMode"
        value="2"
        name="idxm_radio"
        type="radio"
        @update:checked="update('indexingMode')"
        >{{
          t("memories", "Index per-user timeline folders (not recommended)")
        }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="indexingMode"
        value="3"
        name="idxm_radio"
        type="radio"
        @update:checked="update('indexingMode')"
        >{{ t("memories", "Index a fixed relative path") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :checked.sync="indexingMode"
        value="0"
        name="idxm_radio"
        type="radio"
        @update:checked="update('indexingMode')"
        >{{ t("memories", "Disable background indexing") }}
      </NcCheckboxRadioSwitch>

      <NcTextField
        :label="t('memories', 'Indexing path (relative, all users)')"
        :label-visible="true"
        :value="indexingPath"
        @change="update('indexingPath', $event.target.value)"
        v-if="indexingMode === '3'"
      />
    </p>

    {{
      t("memories", "For advanced usage, perform a run of indexing by running:")
    }}
    <br />
    <code>occ memories:index</code>
    <br />
    {{ t("memories", "Run index in parallel with 4 threads:") }}
    <br />
    <code>bash -c 'for i in {1..4}; do (occ memories:index &amp;); done'</code>
    <br />
    {{ t("memories", "Force re-indexing of all files:") }}
    <br />
    <code>occ memories:index --force</code>
    <br />
    {{ t("memories", "You can limit indexing by user and/or folder:") }}
    <br />
    <code>occ memories:index --user=admin --folder=/Photos/</code>
    <br />
    {{ t("memories", "Clear all existing index tables:") }}
    <br />
    <code>occ memories:index --clear</code>
    <br />

    <br />
    {{
      t(
        "memories",
        "The following MIME types are configured for preview generation correctly. More documentation:"
      )
    }}
    <a
      href="https://github.com/pulsejet/memories/wiki/File-Type-Support"
      target="_blank"
    >
      {{ t("memories", "External Link") }}
    </a>
    <br />
    <code v-if="status"
      ><template v-for="mime in status.mimes"
        >{{ mime }}<br :key="mime" /></template
    ></code>

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
        <NcNoteCard v-if="gisType < 0" type="warning">
          {{
            t(
              "memories",
              "Reverse geocoding has not been configured ({gisType}).",
              { gisType }
            )
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
          "If the button below does not work for importing the planet data, use the following command:"
        )
      }}
      <br />
      <code>occ memories:places-setup</code>
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
      <input name="actiontoken" type="hidden" :value="actionToken" />
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
        :disabled="!enableTranscoding"
      />

      <NcTextField
        :label="t('memories', 'ffprobe path')"
        :label-visible="true"
        :value="ffprobePath"
        @change="update('ffprobePath', $event.target.value)"
        :disabled="!enableTranscoding"
      />

      <br />
      {{ t("memories", "Global default video quality (user may override)") }}
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="videoDefaultQuality"
        value="0"
        name="vdq_radio"
        type="radio"
        @update:checked="update('videoDefaultQuality')"
        >{{ t("memories", "Auto (adaptive transcode)") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="videoDefaultQuality"
        value="-1"
        name="vdq_radio"
        type="radio"
        @update:checked="update('videoDefaultQuality')"
        >{{ t("memories", "Original (transcode with max quality)") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
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
          "Memories uses the go-vod transcoder. You can run go-vod exernally (e.g. in a separate Docker container for hardware acceleration) or use the built-in transcoder. To use an external transcoder, enable the following option and follow the instructions in the documentation:"
        )
      }}
      <a
        target="_blank"
        href="https://github.com/pulsejet/memories/wiki/HW-Transcoding"
      >
        {{ t("memories", "External Link") }}
      </a>

      <template v-if="status">
        <NcNoteCard :type="binaryStatusType(status.govod)">
          {{ binaryStatus("go-vod", status.govod) }}
        </NcNoteCard>
      </template>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding"
        :checked.sync="enableExternalTranscoder"
        @update:checked="update('enableExternalTranscoder')"
        type="switch"
      >
        {{ t("memories", "Enable external transcoder (go-vod)") }}
      </NcCheckboxRadioSwitch>

      <NcTextField
        :disabled="!enableTranscoding"
        :label="t('memories', 'Binary path (local only)')"
        :label-visible="true"
        :value="goVodPath"
        @change="update('goVodPath', $event.target.value)"
      />

      <NcTextField
        :disabled="!enableTranscoding"
        :label="t('memories', 'Bind address (local only)')"
        :label-visible="true"
        :value="goVodBind"
        @change="update('goVodBind', $event.target.value)"
      />

      <NcTextField
        :disabled="!enableTranscoding"
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
        :checked.sync="enableVaapi"
        @update:checked="update('enableVaapi')"
        type="switch"
      >
        {{ t("memories", "Enable acceleration with VA-API") }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !enableVaapi"
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
        :disabled="!enableTranscoding"
        :checked.sync="enableNvenc"
        @update:checked="update('enableNvenc')"
        type="switch"
      >
        {{ t("memories", "Enable acceleration with NVENC") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !enableNvenc"
        :checked.sync="enableNvencTemporalAQ"
        @update:checked="update('enableNvencTemporalAQ')"
        type="switch"
      >
        {{ t("memories", "Enable NVENC Temporal AQ") }}
      </NcCheckboxRadioSwitch>

      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !enableNvenc"
        :checked.sync="nvencScaler"
        value="npp"
        name="nvence_scaler_radio"
        type="radio"
        @update:checked="update('nvencScaler')"
        class="m-radio"
        >{{ t("memories", "NPP scaler") }}
      </NcCheckboxRadioSwitch>
      <NcCheckboxRadioSwitch
        :disabled="!enableTranscoding || !enableNvenc"
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
  indexingMode: "memories.index.mode",
  indexingPath: "memories.index.path",

  gisType: "memories.gis_type",

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
  last_index_job_start: number;
  last_index_job_duration: number;
  last_index_job_status: string;
  last_index_job_status_type: string;

  bad_encryption: boolean;
  indexed_count: number;
  mimes: string[];
  gis_type: number;
  gis_count?: number;
  exiftool: BinaryStatus;
  perl: BinaryStatus;
  ffmpeg: BinaryStatus;
  ffprobe: BinaryStatus;
  govod: BinaryStatus;
  vaapi_dev: "ok" | "not_found" | "not_readable";

  action_token: string;
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
    indexingMode: "0",
    indexingPath: "",

    gisType: 0,

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
      const warnSetup = this.t(
        "memories",
        "Looks like the database is already setup. Are you sure you want to redownload planet data?"
      );
      const warnLong = this.t(
        "memories",
        "You are about to download the planet database. This may take a while."
      );
      const warnReindex = this.t(
        "memories",
        "This may also cause all photos to be re-indexed!"
      );
      const msg =
        (this.status.gis_count ? warnSetup : warnLong) + " " + warnReindex;
      if (!confirm(msg)) {
        event.preventDefault();
        event.stopPropagation();
        return;
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

    actionToken() {
      return this.status?.action_token || "";
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

  code {
    padding-left: 10px;
    -webkit-box-decoration-break: clone;
    box-decoration-break: clone;
  }
}
</style>
