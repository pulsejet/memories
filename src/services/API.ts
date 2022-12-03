import { generateUrl } from "@nextcloud/router";

const BASE = "/apps/memories/api";

const gen = generateUrl;

/** Add auth token to this URL */
function tok(url: string) {
  if (vuerouter.currentRoute.name === "folder-share") {
    url = API.Q(url, `folder_share=${vuerouter.currentRoute.params.token}`);
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

  static TAG_LIST() {
    return gen(`${BASE}/tags`);
  }

  static TAG_PREVIEWS(tag: string) {
    return gen(`${BASE}/tag-previews?tag=${tag}`);
  }

  static FACE_LIST() {
    return gen(`${BASE}/faces`);
  }

  static FACE_PREVIEWS(face: string | number) {
    return gen(`${BASE}/faces/preview/{face}`, { face });
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

  static CONFIG(setting: string) {
    return gen(`${BASE}/config/{setting}`, { setting });
  }
}
