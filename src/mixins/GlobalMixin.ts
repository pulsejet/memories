import { translate as t, translatePlural as n } from "@nextcloud/l10n";
import { constants } from "../services/Utils";
import { loadState } from "@nextcloud/initial-state";

export default {
  name: "GlobalMixin",

  data() {
    return {
      ...constants,

      state_noDownload: loadState("memories", "no_download", false) !== false,
    };
  },

  methods: {
    t,
    n,
  },
};
