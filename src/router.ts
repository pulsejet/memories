import { generateUrl } from "@nextcloud/router";
import { translate as t, translatePlural as n } from "@nextcloud/l10n";
import { createRouter, createWebHistory } from "vue-router";
import Timeline from "./components/Timeline.vue";

export default createRouter({
  // if index.php is in the url AND we got this far, then it's working:
  // let's keep using index.php in the url
  history: createWebHistory(generateUrl("/apps/memories")),

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
      redirect() {
        return generateUrl("/apps/maps");
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
  ],
});
