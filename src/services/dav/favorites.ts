import * as base from "./base";
import { generateUrl } from "@nextcloud/router";
import { encodePath } from "@nextcloud/paths";
import { showError } from "@nextcloud/dialogs";
import { translate as t, translatePlural as n } from "@nextcloud/l10n";
import axios from "@nextcloud/axios";
import { IPhoto } from "../../types";

/**
 * Favorite a file
 * https://github.com/nextcloud/photos/blob/7687e214f9b0f71a2cc73778b8b22ab781490a3b/src/services/FileActions.js
 *
 * @param fileName - The file's name
 * @param favoriteState - The new favorite state
 */
export async function favoriteFile(fileName: string, favoriteState: boolean) {
  let encodedPath = encodePath(fileName);
  while (encodedPath[0] === "/") {
    encodedPath = encodedPath.substring(1);
  }

  try {
    return axios.post(
      `${generateUrl("/apps/files/api/v1/files/")}${encodedPath}`,
      {
        tags: favoriteState ? ["_$!<Favorite>!$_"] : [],
      }
    );
  } catch (error) {
    console.error("Failed to favorite", fileName, error);
    showError(t("memories", "Failed to favorite {fileName}.", { fileName }));
  }
}

/**
 * Favorite all files in a given list of Ids
 *
 * @param photos list of photos
 * @param favoriteState the new favorite state
 * @returns generator of lists of file ids that were state-changed
 */
export async function* favoriteFilesByIds(
  photos: IPhoto[],
  favoriteState: boolean
) {
  if (photos.length === 0) {
    return;
  }

  // Get files data
  let fileInfos: any[] = [];
  try {
    fileInfos = await base.getFiles(photos);
  } catch (e) {
    console.error("Failed to get file info", photos, e);
    showError(t("memories", "Failed to favorite files."));
    return;
  }

  if (fileInfos.length !== photos.length) {
    showError(t("memories", "Failed to favorite some files."));
  }

  // Favorite each file
  const calls = fileInfos.map((fileInfo) => async () => {
    try {
      await favoriteFile(fileInfo.filename, favoriteState);
      return fileInfo.fileid as number;
    } catch (error) {
      console.error("Failed to favorite", fileInfo, error);
      showError(t("memories", "Failed to favorite {fileName}.", fileInfo));
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}
