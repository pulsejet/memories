import { computed, type ComputedRef } from 'vue';
import { useRoute } from 'vue-router';

import { constants as c } from '@services/utils';

export function useRouteIsBase(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'timeline');
}

export function useRouteIsFolders(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'folders');
}

export function useRouteIsFavorites(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'favorites');
}

export function useRouteIsVideos(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'videos');
}

export function useRouteIsPanoramas(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'panoramas');
}

export function useRouteIsAlbums(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'albums');
}

export function useRouteIsArchive(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'archive');
}

export function useRouteIsThisDay(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'thisday');
}

export function useRouteIsRecognize(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'recognize');
}

export function useRouteIsFaceRecognition(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'facerecognition');
}

export function useRouteIsPlaces(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'places');
}

export function useRouteIsTags(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'tags');
}

export function useRouteIsFolderShare(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'folder-share');
}

export function useRouteIsAlbumShare(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'album-share');
}

export function useRouteIsMap(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'map');
}

export function useRouteIsExplore(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'explore');
}

export function useRouteIsSearch(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'search');
}

export function useRouteIsNxSetup(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'nxsetup');
}

export function useRouteIsPublic(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name?.toString().endsWith('-share') ?? false);
}

export function useRouteIsPeople(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => ['recognize', 'facerecognition'].includes(route.name?.toString() ?? ''));
}

export function useRouteIsRecognizeUnassigned(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'recognize' && route.params.name === c.FACE_NULL);
}

export function useRouteIsPlacesUnassigned(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() => route.name === 'places' && route.params.name === c.PLACES_NULL);
}

export function useRouteIsCluster(): ComputedRef<boolean> {
  const route = useRoute();
  return computed(() =>
    ['albums', 'recognize', 'facerecognition', 'places', 'tags'].includes(route.name?.toString() ?? ''),
  );
}
