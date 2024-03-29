import { initstate } from '@services/utils';
import type { IDay } from '@typings';

const { singleItem } = initstate;

export function isSingleItem(): boolean {
  return Boolean(singleItem?.fileid);
}

export async function getSingleItemData(): Promise<IDay[]> {
  if (!singleItem?.fileid) return [];

  // Make days array
  singleItem.key = String(singleItem.fileid);
  const days = [
    {
      dayid: singleItem.dayid,
      count: 1,
      detail: [singleItem],
    },
  ];

  // Return copy to prevent circular reference
  return JSON.parse(JSON.stringify(days));
}
