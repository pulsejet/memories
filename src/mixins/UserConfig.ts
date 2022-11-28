import { Component, Vue } from "vue-property-decorator";
import { emit, subscribe, unsubscribe } from "@nextcloud/event-bus";
import { generateUrl } from "@nextcloud/router";
import { loadState } from "@nextcloud/initial-state";
import axios from "@nextcloud/axios";

const eventName = "memories:user-config-changed";
const localSettings = ["squareThumbs", "showFaceRect"];

@Component
export default class UserConfig extends Vue {
  config_timelinePath: string = loadState(
    "memories",
    "timelinePath",
    <string>""
  );
  config_foldersPath: string = loadState(
    "memories",
    "foldersPath",
    <string>"/"
  );
  config_showHidden =
    loadState("memories", "showHidden", <string>"false") === "true";

  config_tagsEnabled = Boolean(loadState("memories", "systemtags", <string>""));
  config_recognizeEnabled = Boolean(
    loadState("memories", "recognize", <string>"")
  );
  config_mapsEnabled = Boolean(loadState("memories", "maps", <string>""));
  config_albumsEnabled = Boolean(loadState("memories", "albums", <string>""));

  config_squareThumbs = localStorage.getItem("memories_squareThumbs") === "1";
  config_showFaceRect = localStorage.getItem("memories_showFaceRect") === "1";

  config_eventName = eventName;

  created() {
    subscribe(eventName, this.updateLocalSetting);
  }

  beforeDestroy() {
    unsubscribe(eventName, this.updateLocalSetting);
  }

  updateLocalSetting({ setting, value }) {
    this["config_" + setting] = value;
  }

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
      await axios.put(generateUrl("apps/memories/api/config/" + setting), {
        value: value.toString(),
      });
    }

    // Visible elements update setting
    emit(eventName, { setting, value });
  }
}
