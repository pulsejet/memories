import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';

import type { IDay, IPhoto } from '@typings';

/** Day ID for the top-results day; a 2126 date so it reads as new, never old. */
export const TOP_RESULTS_DAYID = 56978;

/** Fixed title for the top-results day. */
export const TOP_RESULTS_TEXT = t('memories', 'Top results');

/** Read a ?q= style route query value as a single string. */
export function routeQueryText(value: unknown): string {
  if (Array.isArray(value)) value = value[0];
  return typeof value === 'string' ? value : '';
}

/** One hit from GET /api/lens/search, score desc. */
export type ILensHit = {
  fileid: number;
  score: number | null;
  w: number | null;
  h: number | null;
  etag: string | null;
  mimetype: string | null;
};

/** Run a lens text search; hits stay in score order. */
export async function searchLens(text: string, limit = 50): Promise<ILensHit[]> {
  const res = await axios.get<ILensHit[]>(API.Q(API.LENS_SEARCH(), { text, limit }));
  if (res.status !== 200) throw res;
  return res.data;
}

/** Fake single day holding search hits for the timeline grid. */
export function fakeSearchDay(hits: ILensHit[]): IDay {
  const detail: IPhoto[] = hits.map((hit) => ({
    fileid: hit.fileid,
    dayid: TOP_RESULTS_DAYID,
    w: hit.w ?? undefined,
    h: hit.h ?? undefined,
    etag: hit.etag ?? undefined,
    mimetype: hit.mimetype ?? undefined,
    flag: 0,
  }));

  return {
    dayid: TOP_RESULTS_DAYID,
    count: detail.length,
    detail,
  };
}

/** Search and wrap hits as fake days (empty when the query is blank). */
export async function getLensSearchDays(text: string, limit = 50): Promise<IDay[]> {
  if (!text.trim()) return [];
  return [fakeSearchDay(await searchLens(text, limit))];
}
