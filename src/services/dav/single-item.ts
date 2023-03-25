import { IDay } from "../../types";
import { loadState } from "@nextcloud/initial-state";

let singleItem = null;
try {
  singleItem = loadState("memories", "single_item", {});
} catch (e) {
  console.error("Could not load single item", e);
}

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
