import { generateUrl } from "@nextcloud/router";
import { translate as t, translatePlural as n } from "@nextcloud/l10n";
import Router from "vue-router";
import Vue from "vue";
import Timeline from "./components/Timeline.vue";
import SplitTimeline from "./components/SplitTimeline.vue";

Vue.use(Router);

export default new Router({
  mode: "history",
  // if index.php is in the url AND we got this far, then it's working:
  // let's keep using index.php in the url
  base: generateUrl("/apps/memories"),
  linkActiveClass: "active",
  routes: [
    {
      path: "/",
      component: Timeline,
      name: "timeline",
      props: (route) => ({
        rootTitle: t("memories", "Timeline"),
      }),
    },

    {
      path: "/folders/:path*",
      component: Timeline,
      name: "folders",
      props: (route) => ({
        rootTitle: t("memories", "Folders"),
      }),
    },

    {
      path: "/favorites",
      component: Timeline,
      name: "favorites",
      props: (route) => ({
        rootTitle: t("memories", "Favorites"),
      }),
    },

    {
      path: "/videos",
      component: Timeline,
      name: "videos",
      props: (route) => ({
        rootTitle: t("memories", "Videos"),
      }),
    },

    {
      path: "/albums/:user?/:name?",
      component: Timeline,
      name: "albums",
      props: (route) => ({
        rootTitle: t("memories", "Albums"),
      }),
    },

    {
      path: "/archive",
      component: Timeline,
      name: "archive",
      props: (route) => ({
        rootTitle: t("memories", "Archive"),
      }),
    },

    {
      path: "/thisday",
      component: Timeline,
      name: "thisday",
      props: (route) => ({
        rootTitle: t("memories", "On this day"),
      }),
    },

    {
      path: "/recognize/:user?/:name?",
      component: Timeline,
      name: "recognize",
      props: (route) => ({
        rootTitle: t("memories", "People"),
      }),
    },

    {
      path: "/facerecognition/:user?/:name?",
      component: Timeline,
      name: "facerecognition",
      props: (route) => ({
        rootTitle: t("memories", "People"),
      }),
    },

    {
      path: "/places/:name*",
      component: Timeline,
      name: "places",
      props: (route) => ({
        rootTitle: t("memories", "Places"),
      }),
    },

    {
      path: "/tags/:name*",
      component: Timeline,
      name: "tags",
      props: (route) => ({
        rootTitle: t("memories", "Tags"),
      }),
    },

    {
      path: "/maps",
      name: "maps",
      // router-link doesn't support external url, let's force the redirect
      beforeEnter() {
        window.open(generateUrl("/apps/maps"), "_blank");
      },
    },

    {
      path: "/s/:token",
      component: Timeline,
      name: "folder-share",
      props: (route) => ({
        rootTitle: t("memories", "Shared Folder"),
      }),
    },

    {
      path: "/a/:token",
      component: Timeline,
      name: "album-share",
      props: (route) => ({
        rootTitle: t("memories", "Shared Album"),
      }),
    },

    {
      path: "/map",
      component: SplitTimeline,
      name: "map",
      props: (route) => ({
        rootTitle: t("memories", "Map"),
      }),
    },
  ],
});
