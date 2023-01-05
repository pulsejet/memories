import { translate as t, translatePlural as n } from "@nextcloud/l10n";
import { constants } from "../services/Utils";
import { loadState } from "@nextcloud/initial-state";
import { defineComponent } from "vue";

export default defineComponent({
  name: "GlobalMixin",

  data: () => ({
    ...constants,

    state_noDownload: loadState("memories", "no_download", false) !== false,
  }),

  methods: {
    t,
    n,
  },
});
