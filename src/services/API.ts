import { generateUrl } from "@nextcloud/router";

const BASE = "/apps/memories/api";

const gen = generateUrl;

/** Add auth token to this URL */
function tok(url: string) {
  const route = vueroute();
  if (route.name === "folder-share") {
    const token = <string>route.params.token;
    url = API.Q(url, `token=${token}`);
  } else if (route.name === "album-share") {
    const token = <string>route.params.token;
    url = API.Q(url, `token=${token}&album=${token}`);
  }
  return url;
}

export class API {
  static Q(url: string, query: string | URLSearchParams | undefined | null) {
    if (!query) return url;

    let queryStr = typeof query === "string" ? query : query.toString();
    if (!queryStr) return url;

    if (url.indexOf("?") > -1) {
      return `${url}&${queryStr}`;
    } else {
      return `${url}?${queryStr}`;
    }
  }

  static DAYS() {
    return tok(gen(`${BASE}/days`));
  }

  static DAY(id: number | string) {
    return tok(gen(`${BASE}/days/{id}`, { id }));
  }

  static ALBUM_LIST(t: "1" | "2" | "3" = "3") {
    return gen(`${BASE}/albums?t=${t}`);
  }

  static ALBUM_DOWNLOAD(user: string, name: string) {
    return gen(`${BASE}/albums/download?name={user}/{name}`, { user, name });
  }

  static PLACE_LIST() {
    return gen(`${BASE}/places`);
  }

  static PLACE_PREVIEW(place: number | string) {
    return gen(`${BASE}/places/preview/{place}`, { place });
  }

  static TAG_LIST() {
    return gen(`${BASE}/tags`);
  }

  static TAG_PREVIEW(tag: string) {
    return gen(`${BASE}/tags/preview/{tag}`, { tag });
  }

  static FACE_LIST(app: "recognize" | "facerecognition") {
    return gen(`${BASE}/${app}/people`);
  }

  static FACE_PREVIEW(
    app: "recognize" | "facerecognition",
    face: string | number
  ) {
    return gen(`${BASE}/${app}/people/preview/{face}`, { face });
  }

  static ARCHIVE(fileid: number) {
    return gen(`${BASE}/archive/{fileid}`, { fileid });
  }

  static IMAGE_PREVIEW(fileid: number) {
    return tok(gen(`${BASE}/image/preview/{fileid}`, { fileid }));
  }

  static IMAGE_INFO(id: number) {
    return tok(gen(`${BASE}/image/info/{id}`, { id }));
  }

  static IMAGE_SETEXIF(id: number) {
    return gen(`${BASE}/image/set-exif/{id}`, { id });
  }

  static IMAGE_JPEG(id: number) {
    return gen(`${BASE}/image/jpeg/{id}`, { id });
  }

  static VIDEO_TRANSCODE(fileid: number) {
    return tok(
      gen(`${BASE}/video/transcode/{videoClientId}/{fileid}/index.m3u8`, {
        videoClientId,
        fileid,
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

  static CONFIG(setting: string) {
    return gen(`${BASE}/config/{setting}`, { setting });
  }

  static CLUSTERS() {
    return tok(gen(`${BASE}/locations/clusters`));
  }
}
