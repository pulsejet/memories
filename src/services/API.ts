import { generateUrl } from "@nextcloud/router";

const BASE = "/apps/memories/api";

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
    return generateUrl(`${BASE}/days`);
  }

  static DAY(id: number | string) {
    return generateUrl(`${BASE}/days/{id}`, { id });
  }

  static ALBUM_LIST(t: "1" | "2" | "3" = "3") {
    return generateUrl(`${BASE}/albums?t=${t}`);
  }

  static TAG_LIST() {
    return generateUrl(`${BASE}/tags`);
  }

  static TAG_PREVIEWS(tag: string) {
    return generateUrl(`${BASE}/tag-previews?tag=${tag}`);
  }

  static FACE_LIST() {
    return generateUrl(`${BASE}/faces`);
  }

  static FACE_PREVIEWS(face: string | number) {
    return generateUrl(`${BASE}/faces/preview/{face}`, { face });
  }

  static ARCHIVE(fileid: number) {
    return generateUrl(`${BASE}/archive/{fileid}`, { fileid });
  }

  static IMAGE_PREVIEW(fileid: number) {
    return generateUrl(`${BASE}/image/preview/{fileid}`, { fileid });
  }

  static IMAGE_INFO(id: number) {
    return generateUrl(`${BASE}/image/info/{id}`, { id });
  }

  static IMAGE_SETEXIF(id: number) {
    return generateUrl(`${BASE}/image/set-exif/{id}`, { id });
  }

  static IMAGE_JPEG(id: number) {
    return generateUrl(`${BASE}/image/jpeg/{id}`, { id });
  }

  static VIDEO_TRANSCODE(fileid: number) {
    return generateUrl(
      `${BASE}/video/transcode/{videoClientId}/{fileid}/index.m3u8`,
      {
        videoClientId,
        fileid,
      }
    );
  }

  static VIDEO_LIVEPHOTO(fileid: number) {
    return generateUrl(`${BASE}/video/livephoto/{fileid}`, { fileid });
  }

  static CONFIG(setting: string) {
    return generateUrl(`${BASE}/config/{setting}`, { setting });
  }
}
