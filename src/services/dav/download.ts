import * as base from "./base";
import { generateUrl } from "@nextcloud/router";
import { showError } from "@nextcloud/dialogs";
import { translate as t } from "@nextcloud/l10n";
import { IPhoto } from "../../types";
import { getAlbumFileInfos } from "./albums";

/**
 * Download a file
 *
 * @param fileNames - The file's names
 */
export async function downloadFiles(fileNames: string[]): Promise<boolean> {
  const randomToken = Math.random().toString(36).substring(2);

  const params = new URLSearchParams();
  params.append("files", JSON.stringify(fileNames));
  params.append("downloadStartSecret", randomToken);

  let downloadURL = generateUrl(`/apps/files/ajax/download.php?${params}`);

  window.location.href = `${downloadURL}downloadStartSecret=${randomToken}`;

  return new Promise((resolve) => {
    const waitForCookieInterval = setInterval(() => {
      const cookieIsSet = document.cookie
        .split(";")
        .map((cookie) => cookie.split("="))
        .findIndex(
          ([cookieName, cookieValue]) =>
            cookieName === "ocDownloadStarted" && cookieValue === randomToken
        );

      if (cookieIsSet) {
        clearInterval(waitForCookieInterval);
        resolve(true);
      }
    }, 50);
  });
}

/**
 * Download public photo
 * @param photo - The photo to download
 */
export async function downloadPublicPhoto(photo: IPhoto) {
  window.location.href = getDownloadLink(photo);
}

/**
 * Download the files given by the fileIds
 * @param photos list of photos
 */
export async function downloadFilesByPhotos(photos: IPhoto[]) {
  if (photos.length === 0) {
    return;
  }

  // Public files
  if (vuerouter.currentRoute.name === "folder-share") {
    for (const photo of photos) {
      await downloadPublicPhoto(photo);
    }
    return;
  }

  // Get files to download
  const fileInfos = await base.getFiles(photos);
  if (fileInfos.length !== photos.length) {
    showError(t("memories", "Failed to download some files."));
  }
  if (fileInfos.length === 0) {
    return;
  }

  await downloadFiles(fileInfos.map((f) => f.filename));
}

/** Get URL to download one file (e.g. for video streaming) */
export function getDownloadLink(photo: IPhoto) {
  // Check if public
  if (vuerouter.currentRoute.name === "folder-share") {
    const token = window.vuerouter.currentRoute.params.token;
    // TODO: allow proper dav access without the need of basic auth
    // https://github.com/nextcloud/server/issues/19700
    return generateUrl(`/s/${token}/download?path={dirname}&files={basename}`, {
      dirname: photo.filename.split("/").slice(0, -1).join("/"),
      basename: photo.basename,
    });
  }

  // Check if albums
  const route = vuerouter.currentRoute;
  if (route.name === "albums") {
    const fInfos = getAlbumFileInfos(
      [photo],
      route.params.user,
      route.params.name
    );
    if (fInfos.length) {
      return `/remote.php/dav${fInfos[0].originalFilename}`;
    }
  }

  return `/remote.php/dav${photo.filename}`;
}
