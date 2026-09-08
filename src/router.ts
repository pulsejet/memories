import { createRouter, createWebHistory, type RouteLocationNormalized, type RouteRecordRaw } from 'vue-router';
import type { App } from 'vue';

import { generateUrl } from '@nextcloud/router';

import Timeline from '@components/Timeline.vue';
import Explore from '@components/Explore.vue';
import SplitTimeline from '@components/SplitTimeline.vue';
import ClusterView from '@components/ClusterView.vue';
import NativeXSetup from '@native/Setup.vue';

import { translate as t } from '@services/l10n';
import { constants as c } from '@services/utils';

// Routes are defined here
export type RouteId =
  | 'Base'
  | 'Folders'
  | 'Favorites'
  | 'Videos'
  | 'Albums'
  | 'Archive'
  | 'ThisDay'
  | 'Recognize'
  | 'FaceRecognition'
  | 'Places'
  | 'Tags'
  | 'FolderShare'
  | 'AlbumShare'
  | 'Map'
  | 'Explore'
  | 'NxSetup';

export const routes: { [key in RouteId]: RouteRecordRaw } = {
  Base: {
    path: '/',
    component: Timeline,
    name: 'timeline',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Timeline') }),
  },

  Folders: {
    path: '/folders/:path(.*)*',
    component: Timeline,
    name: 'folders',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Folders') }),
  },

  Favorites: {
    path: '/favorites',
    component: Timeline,
    name: 'favorites',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Favorites') }),
  },

  Videos: {
    path: '/videos',
    component: Timeline,
    name: 'videos',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Videos') }),
  },

  Albums: {
    path: '/albums/:user?/:name?',
    component: ClusterView,
    name: 'albums',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Albums') }),
  },

  Archive: {
    path: '/archive',
    component: Timeline,
    name: 'archive',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Archive') }),
  },

  ThisDay: {
    path: '/thisday',
    component: Timeline,
    name: 'thisday',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'On this day') }),
  },

  Recognize: {
    path: '/recognize/:user?/:name?',
    component: ClusterView,
    name: 'recognize',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'People') }),
  },

  FaceRecognition: {
    path: '/facerecognition/:user?/:name?',
    component: ClusterView,
    name: 'facerecognition',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'People') }),
  },

  Places: {
    path: '/places/:name(.*)*',
    component: ClusterView,
    name: 'places',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Places') }),
  },

  Tags: {
    path: '/tags/:name(.*)*',
    component: ClusterView,
    name: 'tags',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Tags') }),
  },

  FolderShare: {
    path: '/s/:token/:path(.*)*',
    component: Timeline,
    name: 'folder-share',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Shared Folder') }),
  },

  AlbumShare: {
    path: '/a/:token',
    component: Timeline,
    name: 'album-share',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Shared Album') }),
  },

  Map: {
    path: '/map',
    component: SplitTimeline,
    name: 'map',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Map') }),
  },

  Explore: {
    path: '/explore',
    component: Explore,
    name: 'explore',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Explore') }),
  },

  NxSetup: {
    path: '/nxsetup',
    component: NativeXSetup,
    name: 'nxsetup',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Setup') }),
  },
};

const router = createRouter({
  history: createWebHistory(
    // if index.php is in the url AND we got this far, then it's working:
    // let's keep using index.php in the url
    generateUrl('/apps/memories'),
  ),
  linkActiveClass: 'active',
  routes: Object.values(routes),
});

export default router;

// Define global route checkers
// Injected through globals.d.ts
export type GlobalRouteCheckers = {
  [key in `routeIs${RouteId}`]: boolean;
} & {
  // Extra, special route checkers
  routeIsPublic: boolean;
  routeIsPeople: boolean;
  routeIsRecognizeUnassigned: boolean;
  routeIsPlacesUnassigned: boolean;
  routeIsCluster: boolean;
};

// Implement getters for route checkers
const routeCheckerDefs: { key: keyof GlobalRouteCheckers; condition: (route?: RouteLocationNormalized) => boolean }[] =
  [];

function defineRouteChecker(key: keyof GlobalRouteCheckers, condition: (route?: RouteLocationNormalized) => boolean) {
  routeCheckerDefs.push({ key, condition });
}

// Register the checkers defined above as a global mixin (Options API compatible).
// Each checker reads this.$route reactively via computed.
// Note: Vue invokes Options-API computed getters with the component instance
// as both `this` and the first argument, so the condition is wrapped to drop
// that argument (it would otherwise shadow the route lookup).
export function registerRouteCheckers(app: App) {
  const computed: Record<string, (this: { $route?: RouteLocationNormalized }) => boolean> = {};
  for (const { key, condition } of routeCheckerDefs) {
    computed[key] = function (this) {
      return condition(this.$route);
    };
  }
  app.mixin({ computed });
}

// Build basic route checkers
for (const [key, value] of Object.entries(routes)) {
  const key_ = key as RouteId;
  defineRouteChecker(`routeIs${key_}`, (route) => route?.name === value.name);
}

// Extra route checkers
defineRouteChecker('routeIsPublic', (route) => route?.name?.toString().endsWith('-share') ?? false);
defineRouteChecker('routeIsPeople', (route) =>
  [routes.Recognize.name, routes.FaceRecognition.name].includes(route?.name?.toString() ?? ''),
);
defineRouteChecker(
  'routeIsRecognizeUnassigned',
  (route) => route?.name === routes.Recognize.name && route!.params.name === c.FACE_NULL,
);
defineRouteChecker(
  'routeIsPlacesUnassigned',
  (route) => route?.name === routes.Places.name && route!.params.name === c.PLACES_NULL,
);
defineRouteChecker('routeIsCluster', (route) =>
  [
    routes.Albums.name,
    routes.Recognize.name,
    routes.FaceRecognition.name,
    routes.Places.name,
    routes.Tags.name,
  ].includes(route?.name?.toString() ?? ''),
);
