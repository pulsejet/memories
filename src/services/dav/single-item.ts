import { IDay, IPhoto } from '../../types';
import { loadState } from '@nextcloud/initial-state';

let singleItem: IPhoto | null = null;
try {
  singleItem = loadState('memories', 'single_item', null);
} catch (e) {
  console.error('Could not load single item', e);
}

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
