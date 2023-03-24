import { ICluster } from "../../types";
import { API } from "../API";
import axios from "@nextcloud/axios";

/**
 * Get list of tags.
 */
export async function getTags() {
  return (await axios.get<ICluster[]>(API.TAG_LIST())).data;
}
