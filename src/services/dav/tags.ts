import { getLanguage } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';

import client from './client';

import { API } from '@services/API';
import { translate as t } from '@services/l10n';

import type { ICluster } from '@typings';

export interface ITag {
  id: number;
  displayName: string;
  userVisible: boolean;
  userAssignable: boolean;
  canAssign: boolean;
}

/**
 * Get list of tags.
 */
export async function getTags() {
  const tags = (await axios.get<ICluster[]>(API.TAG_LIST())).data;

  // Translate tag names
  tags.forEach((tag) => (tag.display_name = t('recognize', tag.name)));

  // Sort tags by display name (locale aware)
  tags.sort((a, b) => a.display_name!.localeCompare(b.display_name!, getLanguage(), { numeric: true }));

  return tags;
}

/**
 * Create a new tag.
 *
 * https://github.com/nextcloud/server/blob/9a7e2b15580578b4de3eb36808dc466a9fd6b976/apps/systemtags/src/services/api.ts#L101-L127
 */
export async function createTag(tag: ITag): Promise<ITag> {
  const path = '/systemtags';
  const postData = {
    ...tag,
    name: tag.displayName, // weird
    id: undefined,
  };

  try {
    const { headers } = await client.customRequest(path, {
      method: 'POST',
      data: postData,
    });

    const contentLocation = headers.get('content-location');
    if (contentLocation) {
      return {
        ...tag,
        id: parseIdFromLocation(contentLocation),
      };
    }

    throw new Error(t('memories', 'No content-location header found'));
  } catch (error) {
    if (error?.status === 409) {
      // Tag already exists. Now this may happen e.g. if the tag isn't
      // assignable or visible to the user and cause problems later.
      // But that's not our problem here.
      return tag;
    }

    throw new Error(
      t('memories', 'Failed to create tag {name}: {error}', {
        name: tag.displayName,
        error: error.message,
      }),
    );
  }
}

/**
 * Parse id from `Content-Location` header
 * https://github.com/nextcloud/server/blob/9a7e2b15580578b4de3eb36808dc466a9fd6b976/apps/systemtags/src/utils.ts#L36C1-L55C2
 */
function parseIdFromLocation(url: string): number {
  const queryPos = url.indexOf('?');
  if (queryPos > 0) {
    url = url.substring(0, queryPos);
  }

  const parts: string[] = url.split('/');
  let result: string | undefined;
  do {
    result = parts[parts.length - 1];
    parts.pop();
    // note: first result can be empty when there is a trailing slash,
    // so we take the part before that
  } while (!result && parts.length > 0);

  return Number(result);
}
