import * as base from './base';
import { generateUrl } from '@nextcloud/router'
import { encodePath } from '@nextcloud/paths'
import { showError } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'

/**
 * Favorite a file
 * https://github.com/nextcloud/photos/blob/7687e214f9b0f71a2cc73778b8b22ab781490a3b/src/services/FileActions.js
 *
 * @param fileName - The file's name
 * @param favoriteState - The new favorite state
 */
export async function favoriteFile(fileName: string, favoriteState: boolean) {
	let encodedPath = encodePath(fileName)
	while (encodedPath[0] === '/') {
		encodedPath = encodedPath.substring(1)
	}

	try {
		return axios.post(
			`${generateUrl('/apps/files/api/v1/files/')}${encodedPath}`,
			{
				tags: favoriteState ? ['_$!<Favorite>!$_'] : [],
			},
		)
	} catch (error) {
		console.error('Failed to favorite', fileName, error)
		showError(t('memories', 'Failed to favorite {fileName}.', { fileName }))
	}
}

/**
 * Favorite all files in a given list of Ids
 *
 * @param fileIds list of file ids
 * @param favoriteState the new favorite state
 * @returns generator of lists of file ids that were state-changed
 */
export async function* favoriteFilesByIds(fileIds: number[], favoriteState: boolean) {
    const fileIdsSet = new Set(fileIds);

    if (fileIds.length === 0) {
        return;
    }

    // Get files data
    let fileInfos: any[] = [];
    try {
        fileInfos = await base.getFiles(fileIds.filter(f => f));
    } catch (e) {
        console.error('Failed to get file info', fileIds, e);
        showError(t('memories', 'Failed to favorite files.'));
        return;
    }

    // Favorite each file
    fileInfos = fileInfos.filter((f) => fileIdsSet.has(f.fileid));
    const calls = fileInfos.map((fileInfo) => async () => {
        try {
            await favoriteFile(fileInfo.filename, favoriteState);
            return fileInfo.fileid as number;
        } catch (error) {
            console.error('Failed to favorite', fileInfo, error);
            showError(t('memories', 'Failed to favorite {fileName}.', fileInfo));
            return 0;
        }
    });

    yield* base.runInParallel(calls, 10);
}