import { createRouter, createWebHistory, createMemoryHistory } from 'vue-router';

import { generateUrl } from '@nextcloud/router';

import { translate as t } from '@services/l10n';

import type { RouteLocationNormalized, RouteRecordRaw } from 'vue-router';

export const routes = {
  Base: {
    path: '/',
    component: () => import('@components/Timeline.vue'),
    name: 'timeline',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Timeline') }),
  },

  Folders: {
    path: '/folders/:path(.*)*',
    component: () => import('@components/Timeline.vue'),
    name: 'folders',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Folders') }),
  },

  Favorites: {
    path: '/favorites',
    component: () => import('@components/Timeline.vue'),
    name: 'favorites',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Favorites') }),
  },

  Videos: {
    path: '/videos',
    component: () => import('@components/Timeline.vue'),
    name: 'videos',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Videos') }),
  },

  Panoramas: {
    path: '/panoramas',
    component: () => import('@components/Timeline.vue'),
    name: 'panoramas',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Panoramas') }),
  },

  AlbumsList: {
    path: '/albums',
    component: () => import('@components/ClusterView.vue'),
    name: 'albums-list',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Albums') }),
  },

  Albums: {
    path: '/albums/:user/:name',
    component: () => import('@components/Timeline.vue'),
    name: 'albums',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Albums') }),
  },

  Archive: {
    path: '/archive',
    component: () => import('@components/Timeline.vue'),
    name: 'archive',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Archive') }),
  },

  ThisDay: {
    path: '/thisday',
    component: () => import('@components/Timeline.vue'),
    name: 'thisday',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'On this day') }),
  },

  RecognizeList: {
    path: '/recognize',
    component: () => import('@components/ClusterView.vue'),
    name: 'recognize-list',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'People') }),
  },

  Recognize: {
    path: '/recognize/:user/:name',
    component: () => import('@components/Timeline.vue'),
    name: 'recognize',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'People') }),
  },

  FaceRecognitionList: {
    path: '/facerecognition',
    component: () => import('@components/ClusterView.vue'),
    name: 'facerecognition-list',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'People') }),
  },

  FaceRecognition: {
    path: '/facerecognition/:user/:name',
    component: () => import('@components/Timeline.vue'),
    name: 'facerecognition',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'People') }),
  },

  PlacesList: {
    path: '/places',
    component: () => import('@components/ClusterView.vue'),
    name: 'places-list',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Places') }),
  },

  Places: {
    path: '/places/:name(.+)',
    component: () => import('@components/Timeline.vue'),
    name: 'places',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Places') }),
  },

  TagsList: {
    path: '/tags',
    component: () => import('@components/ClusterView.vue'),
    name: 'tags-list',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Tags') }),
  },

  Tags: {
    path: '/tags/:name(.+)',
    component: () => import('@components/Timeline.vue'),
    name: 'tags',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Tags') }),
  },

  FolderShare: {
    path: '/s/:token/:path(.*)*',
    component: () => import('@components/Timeline.vue'),
    name: 'folder-share',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Shared Folder') }),
  },

  AlbumShare: {
    path: '/a/:token',
    component: () => import('@components/Timeline.vue'),
    name: 'album-share',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Shared Album') }),
  },

  Map: {
    path: '/map',
    component: () => import('@components/SplitTimeline.vue'),
    name: 'map',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Map') }),
  },

  Explore: {
    path: '/explore',
    component: () => import('@components/Explore.vue'),
    name: 'explore',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Explore') }),
  },

  Search: {
    path: '/search',
    component: () => import('@components/Timeline.vue'),
    name: 'search',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Search') }),
  },

  NxSetup: {
    path: '/nxsetup',
    component: () => import('@native/Setup.vue'),
    name: 'nxsetup',
    props: (route: RouteLocationNormalized) => ({ rootTitle: t('memories', 'Setup') }),
  },
} as const satisfies Record<string, RouteRecordRaw>;

// If index.php is in the url AND we got this far, then it's working:
// let's keep using index.php in the url
const base = generateUrl('/apps/memories');

// Use memory history for admin panel
const isAdmin = window.location.pathname.includes('settings/admin/memories');

const router = createRouter({
  history: isAdmin ? createMemoryHistory() : createWebHistory(base),
  linkActiveClass: 'active',
  routes: Object.values(routes),
});

export default router;
