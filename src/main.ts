/// <reference types="@nextcloud/typings" />

import "reflect-metadata";
import Vue from "vue";
import VueVirtualScroller from "vue-virtual-scroller";
import "vue-virtual-scroller/dist/vue-virtual-scroller.css";
import XImg from "./components/frame/XImg.vue";
import GlobalMixin from "./mixins/GlobalMixin";

import App from "./App.vue";
import Admin from "./Admin.vue";
import router from "./router";
import { Route } from "vue-router";
import { generateFilePath } from "@nextcloud/router";
import { getRequestToken } from "@nextcloud/auth";
import { IPhoto } from "./types";

import type PlyrType from "plyr";
import type videojsType from "video.js";

import "./global.scss";

// Global exposed variables
declare global {
  var mode: "admin" | "user";

  var vueroute: () => Route;
  var OC: Nextcloud.v24.OC;
  var OCP: Nextcloud.v24.OCP;

  var editMetadata: (photos: IPhoto[], sections?: number[]) => void;
  var sharePhoto: (photo: IPhoto) => void;
  var shareNodeLink: (path: string, immediate?: boolean) => Promise<void>;

  var mSidebar: {
    open: (fileid: number, filename?: string, forceNative?: boolean) => void;
    close: () => void;
    setTab: (tab: string) => void;
    getWidth: () => number;
  };

  var currentViewerPhoto: IPhoto;

  var windowInnerWidth: number; // cache
  var windowInnerHeight: number; // cache

  var __webpack_nonce__: string;
  var __webpack_public_path__: string;

  var vidjs: typeof videojsType;
  var Plyr: typeof PlyrType;
  var videoClientId: string;
  var videoClientIdPersistent: string;
}

// Allow global access to the router
globalThis.vueroute = () => router.currentRoute;

// Cache these for better performance
globalThis.windowInnerWidth = window.innerWidth;
globalThis.windowInnerHeight = window.innerHeight;

// CSP config for webpack dynamic chunk loading
__webpack_nonce__ = window.btoa(getRequestToken());

// Correct the root of the app for chunk loading
// OC.linkTo matches the apps folders
// OC.generateUrl ensure the index.php (or not)
// We do not want the index.php since we're loading files
__webpack_public_path__ = generateFilePath("memories", "", "js/");

// Generate client id for this instance
// Does not need to be cryptographically secure
const getClientId = () =>
  Math.random().toString(36).substring(2, 15).padEnd(12, "0");
globalThis.videoClientId = getClientId();
globalThis.videoClientIdPersistent = localStorage.getItem(
  "videoClientIdPersistent"
);
if (!globalThis.videoClientIdPersistent) {
  globalThis.videoClientIdPersistent = getClientId();
  localStorage.setItem(
    "videoClientIdPersistent",
    globalThis.videoClientIdPersistent
  );
}

Vue.mixin(GlobalMixin);
Vue.use(VueVirtualScroller);
Vue.component("XImg", XImg);

// https://github.com/nextcloud/photos/blob/156f280c0476c483cb9ce81769ccb0c1c6500a4e/src/main.js
// TODO: remove when we have a proper fileinfo standalone library
// original scripts are loaded from
// https://github.com/nextcloud/server/blob/5bf3d1bb384da56adbf205752be8f840aac3b0c5/lib/private/legacy/template.php#L120-L122
window.addEventListener("DOMContentLoaded", () => {
  if (!globalThis.OCA.Files) {
    globalThis.OCA.Files = {};
  }
  // register unused client for the sidebar to have access to its parser methods
  Object.assign(
    globalThis.OCA.Files,
    {
      App: {
        fileList: { filesClient: (<any>globalThis.OC.Files).getClient() },
      },
    },
    globalThis.OCA.Files
  );
});

let app = null;

const adminSection = document.getElementById("memories-admin-content");
if (adminSection) {
  app = new Vue({
    el: "#memories-admin-content",
    render: (h) => h(Admin),
  });
  globalThis.mode = "admin";
} else {
  app = new Vue({
    el: "#content",
    router,
    render: (h) => h(App),
  });
  globalThis.mode = "user";
}

export default app;
