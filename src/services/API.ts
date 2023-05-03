import { generateUrl } from '@nextcloud/router';
import { ClusterTypes } from '../types';

const BASE = '/apps/memories/api';

const gen = generateUrl;

/** Add auth token to this URL */
function tok(url: string) {
  const route = vueroute();
  if (route.name === 'folder-share') {
    const token = <string>route.params.token;
    url = API.Q(url, { token });
  } else if (route.name === 'album-share') {
    const token = <string>route.params.token;
    url = API.Q(url, { token, albums: token });
  }
  return url;
}

export enum DaysFilterType {
  FAVORITES = 'fav',
  VIDEOS = 'vid',
  FOLDER = 'folder',
  ARCHIVE = 'archive',
  ALBUM = 'albums',
  RECOGNIZE = 'recognize',
  FACERECOGNITION = 'facerecognition',
  PLACE = 'places',
  TAG = 'tags',
  MAP_BOUNDS = 'mapbounds',

  FACE_RECT = 'facerect',
  RECURSIVE = 'recursive',
  MONTH_VIEW = 'monthView',
  REVERSE = 'reverse',
}

export class API {
  static Q(url: string, query: string | URLSearchParams | Object | undefined | null) {
    if (!query) return url;

    if (typeof query === 'object') {
      // Clean up undefined and null
      for (const key of Object.keys(query)) {
        if (query[key] === undefined || query[key] === null) {
          delete query[key];
        }
      }

      // Check if nothing in query
      if (!Object.keys(query).length) return url;

      // Convert to search params
      query = new URLSearchParams(<any>query);
    }

    if (query instanceof URLSearchParams) {
      query = query.toString();
    }

    if (!query) return url;

    if (url.indexOf('?') > -1) {
      return `${url}&${query}`;
    } else {
      return `${url}?${query}`;
    }
  }

  static DAYS() {
    return tok(gen(`${BASE}/days`));
  }

  static DAY(id: number | string) {
    return tok(gen(`${BASE}/days/{id}`, { id }));
  }

  static DAYS_FILTER(query: any, filter: DaysFilterType, value: string = '1') {
    query[filter] = value;
  }

  static FOLDERS_SUB() {
    return tok(gen(`${BASE}/folders/sub`));
  }

  static ALBUM_LIST(t: 1 | 2 | 3 = 3) {
    return gen(`${BASE}/clusters/albums?t=${t}`);
  }

  static ALBUM_DOWNLOAD(user: string, name: string) {
    return gen(`${BASE}/clusters/albums/download?name={user}/{name}`, {
      user,
      name,
    });
  }

  static PLACE_LIST() {
    return gen(`${BASE}/clusters/places`);
  }

  static TAG_LIST() {
    return gen(`${BASE}/clusters/tags`);
  }

  static TAG_SET(fileid: string | number) {
    return gen(`${BASE}/tags/set/{fileid}`, { fileid });
  }

  static FACE_LIST(app: 'recognize' | 'facerecognition') {
    return gen(`${BASE}/clusters/${app}`);
  }

  static CLUSTER_PREVIEW(backend: ClusterTypes, name: string | number) {
    return API.Q(gen(`${BASE}/clusters/${backend}/preview`), { name });
  }

  static ARCHIVE(fileid: number) {
    return gen(`${BASE}/archive/{fileid}`, { fileid });
  }

  static IMAGE_PREVIEW(fileid: number) {
    return tok(gen(`${BASE}/image/preview/{fileid}`, { fileid }));
  }

  static IMAGE_MULTIPREVIEW() {
    return tok(gen(`${BASE}/image/multipreview`));
  }

  static IMAGE_INFO(id: number) {
    return tok(gen(`${BASE}/image/info/{id}`, { id }));
  }

  static IMAGE_SETEXIF(id: number) {
    return gen(`${BASE}/image/set-exif/{id}`, { id });
  }

  static IMAGE_DECODABLE(id: number, etag?: string) {
    return tok(API.Q(gen(`${BASE}/image/decodable/{id}`, { id }), { etag }));
  }

  static IMAGE_EDIT(id: number) {
    return gen(`${BASE}/image/edit/{id}`, { id });
  }

  static VIDEO_TRANSCODE(fileid: number, file = 'index.m3u8') {
    return tok(
      gen(`${BASE}/video/transcode/{videoClientId}/{fileid}/{file}`, {
        videoClientId,
        fileid,
        file,
      })
    );
  }

  static VIDEO_LIVEPHOTO(fileid: number) {
    return tok(gen(`${BASE}/video/livephoto/{fileid}`, { fileid }));
  }

  static DOWNLOAD_REQUEST() {
    return tok(gen(`${BASE}/download`));
  }

  static DOWNLOAD_FILE(handle: string) {
    return tok(gen(`${BASE}/download/{handle}`, { handle }));
  }

  static STREAM_FILE(id: number) {
    return tok(gen(`${BASE}/stream/{id}`, { id }));
  }

  static SHARE_LINKS() {
    return gen(`${BASE}/share/links`);
  }

  static SHARE_NODE() {
    return gen(`${BASE}/share/node`);
  }

  static SHARE_DELETE() {
    return gen(`${BASE}/share/delete`);
  }

  static CONFIG(setting: string) {
    return gen(`${BASE}/config/{setting}`, { setting });
  }

  static CONFIG_GET() {
    return gen(`${BASE}/config`);
  }

  static SYSTEM_CONFIG(setting: string | null) {
    return setting ? gen(`${BASE}/system-config/{setting}`, { setting }) : gen(`${BASE}/system-config`);
  }

  static SYSTEM_STATUS() {
    return gen(`${BASE}/system-status`);
  }

  static OCC_PLACES_SETUP() {
    return gen(`${BASE}/occ/places-setup`);
  }

  static MAP_CLUSTERS() {
    return tok(gen(`${BASE}/map/clusters`));
  }

  static MAP_CLUSTER_PREVIEW(id: number) {
    return tok(gen(`${BASE}/map/clusters/preview/{id}`, { id }));
  }

  static MAP_INIT() {
    return tok(gen(`${BASE}/map/init`));
  }
}
