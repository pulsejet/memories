import { IDay } from '../../types';
import { loadState } from '@nextcloud/initial-state';

let singleItem: any;
try {
  singleItem = loadState('memories', 'single_item', {});
} catch (e) {
  console.error('Could not load single item', e);
}

export function isSingleItem(): boolean {
  return Boolean(singleItem?.fileid);
}

export async function getSingleItemData(): Promise<IDay[]> {
  if (!singleItem?.fileid) return [];

  // Make days array
  singleItem.key = singleItem.fileid;
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
