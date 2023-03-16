import axios from "@nextcloud/axios";
import { showError } from "@nextcloud/dialogs";
import { translate as t } from "@nextcloud/l10n";
import { generateUrl } from "@nextcloud/router";
import { IDay, IPhoto } from "../../types";
import { API } from "../API";
import { constants } from "../Utils";
import client from "../DavClient";
import * as base from "./base";

/**
 * Get list of tags and convert to Days response
 */
export async function getPeopleData(
  app: "recognize" | "facerecognition"
): Promise<IDay[]> {
  // Query for photos
  let data: {
    id: number;
    count: number;
    name: string;
  }[] = [];
  try {
    const res = await axios.get<typeof data>(API.FACE_LIST(app));
    data = res.data;
  } catch (e) {
    throw e;
  }

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
            isface: app,
          } as any)
      ),
    },
  ];
}

export async function updatePeopleFaceRecognition(
  name: string,
  params: object
) {
  if (Number.isInteger(Number(name))) {
    return await axios.put(
      generateUrl(`/apps/facerecognition/api/2.0/cluster/${name}`),
      params
    );
  } else {
    return await axios.put(
      generateUrl(`/apps/facerecognition/api/2.0/person/${name}`),
      params
    );
  }
}

export async function renamePeopleFaceRecognition(
  name: string,
  newName: string
) {
  return await updatePeopleFaceRecognition(name, {
    name: newName,
  });
}

export async function setVisibilityPeopleFaceRecognition(
  name: string,
  visibility: boolean
) {
  return await updatePeopleFaceRecognition(name, {
    visible: visibility,
  });
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
  // Remove each file
  const calls = photos.map((f) => async () => {
    try {
      await client.deleteFile(
        `/recognize/${user}/faces/${name}/${f.fileid}-${f.basename}`
      );
      return f.fileid;
    } catch (e) {
      console.error(e);
      showError(
        t("memories", "Failed to remove {filename} from face.", {
          filename: f.basename,
        })
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}
