import { reactive } from 'vue';
import { createRouter, createWebHistory, type RouteLocationNormalized, type RouteRecordRaw } from 'vue-router';

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
  | 'Panoramas'
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
  | 'Search'
  | 'NxSetup';

export const routes = {
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

  Panoramas: {
    path: '/panoramas',
    component: Timeline,
    name: 'panoramas',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Panoramas') }),
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

  Search: {
    path: '/search',
    component: Timeline,
    name: 'search',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Search') }),
  },

  NxSetup: {
    path: '/nxsetup',
    component: NativeXSetup,
    name: 'nxsetup',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Setup') }),
  },
} as const satisfies Record<RouteId, RouteRecordRaw>;

/** Union of all route names, derived from the route table. */
export type RouteName = (typeof routes)[RouteId]['name'];

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

/** Check the current route name against the given route names. */
function isName(...names: RouteName[]): boolean {
  const name = router.currentRoute.value.name;
  return !!name && (names as (string | symbol)[]).includes(name);
}

/**
 * Shared reactive route checks, keyed by route id.
 * Prefer this over per-component useRoute() wrappers.
 */
export const routeIs = reactive({
  get Base(): boolean {
    return isName(routes.Base.name);
  },
  get Folders(): boolean {
    return isName(routes.Folders.name);
  },
  get Favorites(): boolean {
    return isName(routes.Favorites.name);
  },
  get Videos(): boolean {
    return isName(routes.Videos.name);
  },
  get Panoramas(): boolean {
    return isName(routes.Panoramas.name);
  },
  get Albums(): boolean {
    return isName(routes.Albums.name);
  },
  get Archive(): boolean {
    return isName(routes.Archive.name);
  },
  get ThisDay(): boolean {
    return isName(routes.ThisDay.name);
  },
  get Recognize(): boolean {
    return isName(routes.Recognize.name);
  },
  get FaceRecognition(): boolean {
    return isName(routes.FaceRecognition.name);
  },
  get Places(): boolean {
    return isName(routes.Places.name);
  },
  get Tags(): boolean {
    return isName(routes.Tags.name);
  },
  get FolderShare(): boolean {
    return isName(routes.FolderShare.name);
  },
  get AlbumShare(): boolean {
    return isName(routes.AlbumShare.name);
  },
  get Map(): boolean {
    return isName(routes.Map.name);
  },
  get Explore(): boolean {
    return isName(routes.Explore.name);
  },
  get Search(): boolean {
    return isName(routes.Search.name);
  },
  get NxSetup(): boolean {
    return isName(routes.NxSetup.name);
  },
  get Public(): boolean {
    return isName(routes.AlbumShare.name, routes.FolderShare.name);
  },
  get People(): boolean {
    return isName(routes.Recognize.name, routes.FaceRecognition.name);
  },
  get Cluster(): boolean {
    return isName(
      routes.Albums.name,
      routes.Recognize.name,
      routes.FaceRecognition.name,
      routes.Places.name,
      routes.Tags.name,
    );
  },
  get RecognizeUnassigned(): boolean {
    return isName(routes.Recognize.name) && router.currentRoute.value.params.name === c.FACE_NULL;
  },
  get PlacesUnassigned(): boolean {
    return isName(routes.Places.name) && router.currentRoute.value.params.name === c.PLACES_NULL;
  },
});
