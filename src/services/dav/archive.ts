import * as base from './base';
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

/**
 * Archive or unarchive a single file
 *
 * @param fileid File id
 * @param archive Archive or unarchive
 */
 export async function archiveFile(fileid: number, archive: boolean) {
    return await axios.patch(generateUrl('/apps/memories/api/archive/{fileid}', { fileid }), { archive });
}

/**
 * Archive all files in a given list of Ids
 *
 * @param fileIds list of file ids
 * @param archive Archive or unarchive
 * @returns list of file ids that were deleted
 */
 export async function* archiveFilesByIds(fileIds: number[], archive: boolean) {
    if (fileIds.length === 0) {
        return;
    }

    // Archive each file
    const calls = fileIds.map((id) => async () => {
        try {
            await archiveFile(id, archive);
            return id as number;
        } catch (error) {
            console.error('Failed to (un)archive', id, error);
            const msg = error?.response?.data?.message || t('memories', 'General Failure');
            showError(t('memories', 'Error: {msg}', { msg }));
            return 0;
        }
    });

    yield* base.runInParallel(calls, 10);
}