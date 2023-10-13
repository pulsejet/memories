import { IDay } from '../../types';
import * as utils from '../utils';

const { singleItem } = utils.initState;

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
