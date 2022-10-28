import * as base from "./base";
import { generateUrl } from "@nextcloud/router";
import { showError } from "@nextcloud/dialogs";
import { translate as t } from "@nextcloud/l10n";
import { IPhoto } from "../../types";

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

  const downloadURL = generateUrl(`/apps/files/ajax/download.php?${params}`);

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
 * Download the files given by the fileIds
 * @param photos list of photos
 */
export async function downloadFilesByIds(photos: IPhoto[]) {
  if (photos.length === 0) {
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
