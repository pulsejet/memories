import { IDay, IPhoto, ITag } from "../../types";
import { constants } from "../Utils";
import { API } from "../API";
import axios from "@nextcloud/axios";

/**
 * Get list of tags and convert to Days response
 */
export async function getPlacesData(): Promise<IDay[]> {
  // Query for photos
  let data: {
    osm_id: number;
    count: number;
    name: string;
  }[] = [];
  try {
    const res = await axios.get<typeof data>(API.PLACE_LIST());
    data = res.data;
  } catch (e) {
    throw e;
  }

  // Convert to days response
  return [
    {
      dayid: constants.TagDayID.TAGS,
      count: data.length,
      detail: data.map(
        (tag) =>
          ({
            ...tag,
            id: tag.osm_id,
            fileid: tag.osm_id,
            flag: constants.c.FLAG_IS_TAG,
            istag: true,
            isplace: true,
          } as ITag)
      ),
    },
  ];
}
