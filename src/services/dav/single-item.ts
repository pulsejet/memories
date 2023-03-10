import { IDay } from "../../types";
import { loadState } from "@nextcloud/initial-state";

const singleItem = JSON.parse(loadState("memories", "single_item", "{}"));

export function isSingleItem(): boolean {
  return Boolean(singleItem?.fileid);
}

export async function getSingleItemData(): Promise<IDay[]> {
  if (!singleItem?.fileid) {
    return [];
  }

  singleItem.key = singleItem.fileid;
  return [
    {
      dayid: singleItem.dayid,
      count: 1,
      detail: [singleItem],
    },
  ] as any[];
}
