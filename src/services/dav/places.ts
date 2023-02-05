import { IDay, IPhoto, ITag } from "../../types";
import { constants } from "../Utils";
import axios from "@nextcloud/axios";
import { API } from "../API";

/**
 * Get list of tags and convert to Days response
 */
export async function getPlacesData(): Promise<IDay[]> {
  // Query for photos
  let data: {
    osm_id: number;
    count: number;
    name: string;
    previews: IPhoto[];
  }[] = [];
  try {
    const res = await axios.get<typeof data>(API.PLACE_LIST());
    data = res.data;
  } catch (e) {
    throw e;
  }

  // Add flag to previews
  data.forEach((t) => t.previews?.forEach((preview) => (preview.flag = 0)));

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
