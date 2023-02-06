import { emit, subscribe, unsubscribe } from "@nextcloud/event-bus";
import { loadState } from "@nextcloud/initial-state";
import axios from "@nextcloud/axios";
import { API } from "../services/API";
import { defineComponent } from "vue";

const eventName = "memories:user-config-changed";
const localSettings = ["squareThumbs", "showFaceRect"];

export default defineComponent({
  name: "UserConfig",

  data: () => ({
    config_timelinePath: loadState(
      "memories",
      "timelinePath",
      <string>""
    ) as string,
    config_foldersPath: loadState(
      "memories",
      "foldersPath",
      <string>"/"
    ) as string,
    config_showHidden:
      loadState("memories", "showHidden", <string>"false") === "true",

    config_tagsEnabled: Boolean(
      loadState("memories", "systemtags", <string>"")
    ),
    config_recognizeEnabled: Boolean(
      loadState("memories", "recognize", <string>"")
    ),
    config_facerecognitionInstalled: Boolean(
      loadState("memories", "facerecognitionInstalled", <string>"")
    ),
    config_facerecognitionEnabled: Boolean(
      loadState("memories", "facerecognitionEnabled", <string>"")
    ),
    config_mapsEnabled: Boolean(loadState("memories", "maps", <string>"")),
    config_albumsEnabled: Boolean(loadState("memories", "albums", <string>"")),

    config_placesGis: Number(loadState("memories", "places_gis", <string>"-1")),

    config_squareThumbs: localStorage.getItem("memories_squareThumbs") === "1",
    config_showFaceRect: localStorage.getItem("memories_showFaceRect") === "1",

    config_eventName: eventName,
  }),

  created() {
    subscribe(eventName, this.updateLocalSetting);
  },

  beforeDestroy() {
    unsubscribe(eventName, this.updateLocalSetting);
  },

  methods: {
    updateLocalSetting({ setting, value }) {
      this["config_" + setting] = value;
    },

    async updateSetting(setting: string) {
      const value = this["config_" + setting];

      if (localSettings.includes(setting)) {
        if (typeof value === "boolean") {
          localStorage.setItem("memories_" + setting, value ? "1" : "0");
        } else {
          localStorage.setItem("memories_" + setting, value);
        }
      } else {
        // Long time save setting
        await axios.put(API.CONFIG(setting), {
          value: value.toString(),
        });
      }

      // Visible elements update setting
      emit(eventName, { setting, value });
    },
  },
});
