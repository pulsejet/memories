import Router, { type Route, type RouteConfig } from 'vue-router';
import Vue from 'vue';

import { generateUrl } from '@nextcloud/router';

import Timeline from '@components/Timeline.vue';
import Explore from '@components/Explore.vue';
import SplitTimeline from '@components/SplitTimeline.vue';
import ClusterView from '@components/ClusterView.vue';
import NativeXSetup from '@native/Setup.vue';
import TripVideosList from '@components/TripVideosList.vue';

import { translate as t } from '@services/l10n';
import { constants as c } from '@services/utils';
import staticConfig from '@services/static-config';
import { showError } from '@nextcloud/dialogs';

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
  | 'Trips'
  | 'TripVideos'
  | 'FolderShare'
  | 'AlbumShare'
  | 'Map'
  | 'Explore'
  | 'NxSetup';

export const routes: { [key in RouteId]: RouteConfig } = {
  Base: {
    path: '/',
    component: Timeline,
    name: 'timeline',
    props: (route: Route) => ({ rootTitle: t('memories', 'Timeline') }),
  },

  Folders: {
    path: '/folders/:path*',
    component: Timeline,
    name: 'folders',
    props: (route: Route) => ({ rootTitle: t('memories', 'Folders') }),
  },

  Favorites: {
    path: '/favorites',
    component: Timeline,
    name: 'favorites',
    props: (route: Route) => ({ rootTitle: t('memories', 'Favorites') }),
  },

  Videos: {
    path: '/videos',
    component: Timeline,
    name: 'videos',
    props: (route: Route) => ({ rootTitle: t('memories', 'Videos') }),
  },

  Albums: {
    path: '/albums/:user?/:name?',
    component: ClusterView,
    name: 'albums',
    props: (route: Route) => ({ rootTitle: t('memories', 'Albums') }),
  },

  Archive: {
    path: '/archive',
    component: Timeline,
    name: 'archive',
    props: (route: Route) => ({ rootTitle: t('memories', 'Archive') }),
  },

  ThisDay: {
    path: '/thisday',
    component: Timeline,
    name: 'thisday',
    props: (route: Route) => ({ rootTitle: t('memories', 'On this day') }),
  },

  Recognize: {
    path: '/recognize/:user?/:name?',
    component: ClusterView,
    name: 'recognize',
    props: (route: Route) => ({ rootTitle: t('memories', 'People') }),
  },

  FaceRecognition: {
    path: '/facerecognition/:user?/:name?',
    component: ClusterView,
    name: 'facerecognition',
    props: (route: Route) => ({ rootTitle: t('memories', 'People') }),
  },

  Places: {
    path: '/places/:name*',
    component: ClusterView,
    name: 'places',
    props: (route: Route) => ({ rootTitle: t('memories', 'Places') }),
  },

  Tags: {
    path: '/tags/:name*',
    component: ClusterView,
    name: 'tags',
    props: (route: Route) => ({ rootTitle: t('memories', 'Tags') }),
  },

  Trips: {
    path: '/trips/:name*',
    component: ClusterView,
    name: 'trips',
    props: (route: Route) => ({ rootTitle: t('memories', 'Trips') }),
  },

  TripVideos: {
    path: '/trip-videos',
    component: TripVideosList,
    name: 'trip-videos',
    props: (route: Route) => ({ rootTitle: t('memories', 'Trip Videos') }),
  },

  FolderShare: {
    path: '/s/:token/:path*',
    component: Timeline,
    name: 'folder-share',
    props: (route: Route) => ({ rootTitle: t('memories', 'Shared Folder') }),
  },

  AlbumShare: {
    path: '/a/:token',
    component: Timeline,
    name: 'album-share',
    props: (route: Route) => ({ rootTitle: t('memories', 'Shared Album') }),
  },

  Map: {
    path: '/map',
    component: SplitTimeline,
    name: 'map',
    props: (route: Route) => ({ rootTitle: t('memories', 'Map') }),
  },

  Explore: {
    path: '/explore',
    component: Explore,
    name: 'explore',
    props: (route: Route) => ({ rootTitle: t('memories', 'Explore') }),
  },

  NxSetup: {
    path: '/nxsetup',
    component: NativeXSetup,
    name: 'nxsetup',
    props: (route: Route) => ({ rootTitle: t('memories', 'Setup') }),
  },
};

Vue.use(Router);

// Create the router instance
const router = new Router({
  mode: 'history',
  // if index.php is in the url AND we got this far, then it's working:
  // let's keep using index.php in the url
  base: generateUrl('/apps/memories'),
  linkActiveClass: 'active',
  routes: Object.values(routes),
});

// Helper function to check if trips feature is enabled
async function tripsAllowed() {
  const config = await staticConfig.getAll();
  return config.enable_trips;
}

// Add navigation guard to prevent access to trips when feature is disabled
router.beforeEach(async (to, from, next) => {
  // Check if trying to access trip-related routes
  if (to.name === routes.Trips.name || to.name === routes.TripVideos.name) {
    // Check if trips feature is enabled
    if (!(await tripsAllowed())) {
      showError(t('memories', 'Trips feature is disabled. You can enable it in Settings.'));
      // Redirect to home if coming from external link, otherwise go back
      if (!from.name) {
        next({ name: routes.Base.name });
      } else {
        next(false);
      }
      return;
    }
  }
  next();
});

// Define global route checkers
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
function defineRouteChecker(key: keyof GlobalRouteCheckers, condition: (route?: Route) => boolean) {
  Object.defineProperty(Vue.prototype, key, {
    get() {
      return condition(this.$route);
    },
  });
}

// Build basic route checkers
for (const [key, value] of Object.entries(routes)) {
  const key_ = key as RouteId;
  defineRouteChecker(`routeIs${key_}`, (route) => route?.name === value.name);
}

// Extra route checkers
defineRouteChecker('routeIsPublic', (route) => route?.name?.endsWith('-share') ?? false);
defineRouteChecker('routeIsPeople', (route) =>
  [routes.Recognize.name, routes.FaceRecognition.name].includes(route?.name ?? ''),
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
    routes.Trips.name,
    routes.TripVideos.name,
  ].includes(route?.name ?? ''),
);

export default router;
