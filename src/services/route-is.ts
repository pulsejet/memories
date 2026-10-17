import { reactive, unref, type Ref } from 'vue';
import { useRoute, type RouteLocationNormalized } from 'vue-router';

import { routes } from '@services/router';
import { constants } from '@services/constants';

/** Union of all route names, derived from the route table. */
type RouteName = (typeof routes)[keyof typeof routes]['name'];

/** Route input: reactive route object (e.g. from useRoute()) or a ref to one. */
export type RouteLocation = RouteLocationNormalized | Ref<RouteLocationNormalized>;

/** Check the given route name against the given route names. */
function isName(route: RouteLocation, ...names: RouteName[]): boolean {
  const name = unref(route).name;
  return !!name && (names as (string | symbol)[]).includes(name);
}

/**
 * Shared reactive route checks for the given route.
 * Reads track the route, so they update on navigation.
 * In components prefer useRouteIs(); in services pass a local
 * route, or pipe one through (e.g. via opts).
 */
export function routeIs(route: RouteLocation) {
  return reactive({
    get Base(): boolean {
      return isName(route, routes.Base.name);
    },
    get Folders(): boolean {
      return isName(route, routes.Folders.name);
    },
    get Favorites(): boolean {
      return isName(route, routes.Favorites.name);
    },
    get Videos(): boolean {
      return isName(route, routes.Videos.name);
    },
    get Panoramas(): boolean {
      return isName(route, routes.Panoramas.name);
    },
    get Albums(): boolean {
      return isName(route, routes.AlbumsList.name, routes.Albums.name);
    },
    get Archive(): boolean {
      return isName(route, routes.Archive.name);
    },
    get Trash(): boolean {
      return isName(route, routes.Trash.name);
    },
    get ArchiveFolder(): boolean {
      if (isName(route, routes.Archive.name)) return true;
      if (isName(route, routes.Folders.name)) {
        let path = unref(route).params.path || String();
        if (Array.isArray(path)) path = path.join('/');
        return `/${path}/`.includes('/.archive/');
      }
      return false;
    },
    get ThisDay(): boolean {
      return isName(route, routes.ThisDay.name);
    },
    get Recognize(): boolean {
      return isName(route, routes.RecognizeList.name, routes.Recognize.name);
    },
    get FaceRecognition(): boolean {
      return isName(route, routes.FaceRecognitionList.name, routes.FaceRecognition.name);
    },
    get Places(): boolean {
      return isName(route, routes.PlacesList.name, routes.Places.name);
    },
    get Tags(): boolean {
      return isName(route, routes.TagsList.name, routes.Tags.name);
    },
    get FolderShare(): boolean {
      return isName(route, routes.FolderShare.name);
    },
    get AlbumShare(): boolean {
      return isName(route, routes.AlbumShare.name);
    },
    get Map(): boolean {
      return isName(route, routes.Map.name);
    },
    get Explore(): boolean {
      return isName(route, routes.Explore.name);
    },
    get Search(): boolean {
      return isName(route, routes.Search.name);
    },
    get NxSetup(): boolean {
      return isName(route, routes.NxSetup.name);
    },
    get Public(): boolean {
      return isName(route, routes.AlbumShare.name, routes.FolderShare.name);
    },
    get People(): boolean {
      return isName(
        route,
        routes.RecognizeList.name,
        routes.Recognize.name,
        routes.FaceRecognitionList.name,
        routes.FaceRecognition.name,
      );
    },
    get Cluster(): boolean {
      return isName(
        route,
        routes.AlbumsList.name,
        routes.Albums.name,
        routes.RecognizeList.name,
        routes.Recognize.name,
        routes.FaceRecognitionList.name,
        routes.FaceRecognition.name,
        routes.PlacesList.name,
        routes.Places.name,
        routes.TagsList.name,
        routes.Tags.name,
      );
    },
    get RecognizeUnassigned(): boolean {
      return isName(route, routes.Recognize.name) && unref(route).params.name === constants.FACE_NULL;
    },
    get PlacesUnassigned(): boolean {
      return isName(route, routes.Places.name) && unref(route).params.name === constants.PLACES_NULL;
    },
  });
}

/**
 * Route checks for the current component route.
 * Must be called in setup, e.g. `const routeIs = useRouteIs()`.
 */
export function useRouteIs(): ReturnType<typeof routeIs> {
  return routeIs(useRoute());
}
