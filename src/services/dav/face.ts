import axios from "@nextcloud/axios";
import { showError } from "@nextcloud/dialogs";
import { translate as t } from "@nextcloud/l10n";
import { generateUrl } from "@nextcloud/router";
import { IDay, IPhoto } from "../../types";
import client from "../DavClient";
import { constants } from "../Utils";
import * as base from "./base";

/**
 * Get list of tags and convert to Days response
 */
export async function getPeopleData(app: string): Promise<IDay[]> {
  // Query for photos
  let data: {
    id: number;
    count: number;
    name: string;
    previews: IPhoto[];
  }[] = [];
  try {
    const res = await axios.get<typeof data>(
      generateUrl("/apps/memories/api/" + app + "/people")
    );
    data = res.data;
  } catch (e) {
    throw e;
  }

  // Add flag to previews
  data.forEach((t) => t.previews?.forEach((preview) => (preview.flag = 0)));

  // Convert to days response
  return [
    {
      dayid: constants.TagDayID.FACES,
      count: data.length,
      detail: data.map(
        (face) =>
          ({
            ...face,
            fileid: face.id,
            istag: true,
            isfacerecognize: (app === 'recognize'),
            isfacerecognition: (app === 'facerecognition'),
          } as any)
      ),
    },
  ];
}

export async function getPeopleFacerecognionData(): Promise<IDay[]> {
  return await getPeopleData('facerecognition');
}

export async function getPeopleRecognizeData(): Promise<IDay[]> {
  return await getPeopleData('recognize');
}

/**
 * Remove images from a face.
 *
 * @param user User ID of face
 * @param name Name of face (or ID)
 * @param photos List of photos to remove
 * @returns Generator
 */
export async function* removeFaceImages(
  user: string,
  name: string,
  photos: IPhoto[]
) {
  // Get files data
  let fileInfos = await base.getFiles(photos);

  // Remove each file
  const calls = fileInfos.map((f) => async () => {
    try {
      await client.deleteFile(
        `/recognize/${user}/faces/${name}/${f.fileid}-${f.basename}`
      );
      return f.fileid;
    } catch (e) {
      console.error(e);
      showError(
        t("memories", "Failed to remove {filename} from face.", {
          filename: f.filename,
        })
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}
