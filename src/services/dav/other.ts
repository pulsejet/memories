import { getFiles } from "./base";
import { generateUrl } from "@nextcloud/router";
import { IPhoto } from "../../types";

/**
 * Open the files app with the given photo
 * Opens a new window.
 */
export async function viewInFolder(photo: IPhoto) {
  const f = await getFiles([photo]);
  if (f.length === 0) return;

  const file = f[0];
  const dirPath = file.filename.split("/").slice(0, -1).join("/");
  const url = generateUrl(
    `/apps/files/?dir=${dirPath}&scrollto=${file.fileid}&openfile=${file.fileid}`
  );
  window.open(url, "_blank");
}
