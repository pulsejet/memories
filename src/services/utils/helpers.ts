import { IPhoto } from "../../types";
import { API } from "../API";

/** Get preview URL from photo object */
export function getPreviewUrl(
  photo: IPhoto,
  square: boolean,
  size: number | [number, number] | "screen"
) {
  // Screen-appropriate size
  if (size === "screen") {
    const sw = Math.floor(screen.width * devicePixelRatio);
    const sh = Math.floor(screen.height * devicePixelRatio);
    size = [sw, sh];
  }

  // Convert to array
  const [x, y] = typeof size === "number" ? [size, size] : size;

  return API.Q(API.IMAGE_PREVIEW(photo.fileid), {
    c: photo.etag,
    x,
    y,
    a: square ? "0" : "1",
  });
}

/**
 * Get the path of the folder on folders route
 * This function does not check if this is the folder route
 */
export function getFolderRoutePath(basePath: string) {
  let path: any = vueroute().params.path || "/";
  path = typeof path === "string" ? path : path.join("/");
  path = basePath + "/" + path;
  path = path.replace(/\/\/+/, "/"); // Remove double slashes
  return path;
}

/**
 * Get URL to Live Photo video part
 */
export function getLivePhotoVideoUrl(p: IPhoto, transcode: boolean) {
  return API.Q(API.VIDEO_LIVEPHOTO(p.fileid), {
    etag: p.etag,
    liveid: p.liveid,
    transcode: transcode ? videoClientIdPersistent : undefined,
  });
}

/**
 * Set up hooks to set classes on parent element for Live Photo
 * @param video Video element
 */
export function setupLivePhotoHooks(video: HTMLVideoElement) {
  const div = video.closest(".memories-livephoto") as HTMLDivElement;
  video.onplay = () => {
    div.classList.add("playing");
  };
  video.oncanplay = () => {
    div.classList.add("canplay");
  };
  video.onended = video.onpause = () => {
    div.classList.remove("playing");
  };
}

/**
 * Get route hash for viewer for photo
 */
export function getViewerHash(photo: IPhoto) {
  return `#v/${photo.dayid}/${photo.key}`;
}

/**
 * Get route for viewer for photo
 */
export function getViewerRoute(photo: IPhoto) {
  const $route = globalThis.vueroute();
  return {
    path: $route.path,
    query: $route.query,
    hash: getViewerHash(photo),
  };
}
