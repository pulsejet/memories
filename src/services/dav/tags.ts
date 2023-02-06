import { IDay, IPhoto, ITag } from "../../types";
import { constants, hashCode } from "../Utils";
import { API } from "../API";
import axios from "@nextcloud/axios";

/**
 * Get list of tags and convert to Days response
 */
export async function getTagsData(): Promise<IDay[]> {
  // Query for photos
  let data: {
    id: number;
    count: number;
    name: string;
  }[] = [];
  try {
    const res = await axios.get<typeof data>(API.TAG_LIST());
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
            fileid: hashCode(tag.name),
            flag: constants.c.FLAG_IS_TAG,
            istag: true,
          } as ITag)
      ),
    },
  ];
}
