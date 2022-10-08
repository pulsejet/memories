import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { encodePath } from '@nextcloud/paths'
import { showError } from '@nextcloud/dialogs'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { genFileInfo } from './FileUtils'
import { IDay, IFileInfo, IPhoto, ITag } from '../types';
import { constants, hashCode } from './Utils';
import axios from '@nextcloud/axios'
import client from './DavClient';

const props = `
    <oc:fileid />
    <oc:permissions />
    <d:getlastmodified />
    <d:getetag />
    <d:getcontenttype />
    <d:getcontentlength />
    <nc:has-preview />
    <oc:favorite />
    <d:resourcetype />`;

const IMAGE_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/tiff',
    'image/heic',
];

/**
 * Get file infos for list of files given Ids
 * @param fileIds list of file ids
 * @returns list of file infos
 */
export async function getFiles(fileIds: number[]): Promise<IFileInfo[]> {
    const prefixPath = `/files/${getCurrentUser()!.uid}`;

    // IMPORTANT: if this isn't there, then a blank
    // returns EVERYTHING on the server!
    if (fileIds.length === 0) {
        return [];
    }

    const filter = fileIds.map(fileId => `
        <d:eq>
            <d:prop>
                <oc:fileid/>
            </d:prop>
            <d:literal>${fileId}</d:literal>
        </d:eq>
    `).join('');

    const options = {
        method: 'SEARCH',
        headers: {
            'content-Type': 'text/xml',
        },
        data: `<?xml version="1.0" encoding="UTF-8"?>
            <d:searchrequest xmlns:d="DAV:"
                xmlns:oc="http://owncloud.org/ns"
                xmlns:nc="http://nextcloud.org/ns"
                xmlns:ns="https://github.com/icewind1991/SearchDAV/ns"
                xmlns:ocs="http://open-collaboration-services.org/ns">
                <d:basicsearch>
                    <d:select>
                        <d:prop>
                            ${props}
                        </d:prop>
                    </d:select>
                    <d:from>
                        <d:scope>
                            <d:href>${prefixPath}</d:href>
                            <d:depth>0</d:depth>
                        </d:scope>
                    </d:from>
                    <d:where>
                        <d:or>
                            ${filter}
                        </d:or>
                    </d:where>
                </d:basicsearch>
            </d:searchrequest>`,
        deep: true,
        details: true,
        responseType: 'text',
    };

    let response: any = await client.getDirectoryContents('', options);
    return response.data
        .map((data: any) => genFileInfo(data))
        .map((data: any) => Object.assign({}, data, { filename: data.filename.replace(prefixPath, '') }));
}

/**
 * Get file infos for files in folder path
 * @param folderPath Path to folder
 * @param limit Max number of files to return
 */
export async function getFolderPreviewFileIds(folderPath: string, limit: number): Promise<IFileInfo[]> {
    const prefixPath = `/files/${getCurrentUser()!.uid}`;

    const filter = IMAGE_MIME_TYPES.map(mime => `
        <d:like>
            <d:prop>
                <d:getcontenttype/>
            </d:prop>
            <d:literal>${mime}</d:literal>
        </d:like>
    `).join('');

    const options = {
        method: 'SEARCH',
        headers: {
            'content-Type': 'text/xml',
        },
        data: `<?xml version="1.0" encoding="UTF-8"?>
            <d:searchrequest xmlns:d="DAV:"
                xmlns:oc="http://owncloud.org/ns"
                xmlns:nc="http://nextcloud.org/ns"
                xmlns:ns="https://github.com/icewind1991/SearchDAV/ns"
                xmlns:ocs="http://open-collaboration-services.org/ns">
                <d:basicsearch>
                    <d:select>
                        <d:prop>
                            ${props}
                        </d:prop>
                    </d:select>
                    <d:from>
                        <d:scope>
                            <d:href>${prefixPath}/${folderPath}</d:href>
                            <d:depth>0</d:depth>
                        </d:scope>
                    </d:from>
                    <d:where>
                        <d:or>
                            ${filter}
                        </d:or>
                    </d:where>
                    <d:limit>
                        <d:nresults>${limit}</d:nresults>
                    </d:limit>
                </d:basicsearch>
            </d:searchrequest>`,
        deep: true,
        details: true,
        responseType: 'text',
    };

    let response:any = await client.getDirectoryContents('', options);
    return response.data
        .map((data: any) => genFileInfo(data))
        .map((data: any) => Object.assign({}, data, {
            filename: data.filename.replace(prefixPath, ''),
            etag: data.etag.replace(/&quot;/g, ''), // remove quotes
        }));
}

/**
 * Run promises in parallel, but only n at a time
 * @param promises Array of promise generator funnction (async functions)
 * @param n Number of promises to run in parallel
 */
export async function* runInParallel<T>(promises: (() => Promise<T>)[], n: number) {
    while (promises.length > 0) {
        const promisesToRun = promises.splice(0, n);
        const resultsForThisBatch = await Promise.all(promisesToRun.map(p => p()));
        yield resultsForThisBatch;
    }
    return;
}

/**
 * Delete a single file
 *
 * @param path path to the file
 */
export async function deleteFile(path: string) {
    const prefixPath = `/files/${getCurrentUser()!.uid}`;
    return await client.deleteFile(`${prefixPath}${path}`);
}

/**
 * Delete all files in a given list of Ids
 *
 * @param fileIds list of file ids
 * @returns list of file ids that were deleted
 */
export async function* deleteFilesByIds(fileIds: number[]) {
    const fileIdsSet = new Set(fileIds);

    if (fileIds.length === 0) {
        return;
    }

    // Get files data
    let fileInfos: any[] = [];
    try {
        fileInfos = await getFiles(fileIds.filter(f => f));
    } catch (e) {
        console.error('Failed to get file info for files to delete', fileIds, e);
        showError(t('memories', 'Failed to delete files.'));
        return;
    }

    // Delete each file
    fileInfos = fileInfos.filter((f) => fileIdsSet.has(f.fileid));
    const calls = fileInfos.map((fileInfo) => async () => {
        try {
            await deleteFile(fileInfo.filename);
            return fileInfo.fileid as number;
        } catch (error) {
            console.error('Failed to delete', fileInfo, error);
            showError(t('memories', 'Failed to delete {fileName}.', fileInfo));
            return 0;
        }
    });

    yield* runInParallel(calls, 10);
}

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

    yield* runInParallel(calls, 10);
}


/**
 * Download a file
 *
 * @param fileNames - The file's names
 */
 export async function downloadFiles(fileNames: string[]): Promise<boolean> {
    const randomToken = Math.random().toString(36).substring(2)

    const params = new URLSearchParams()
    params.append('files', JSON.stringify(fileNames))
    params.append('downloadStartSecret', randomToken)

    const downloadURL = generateUrl(`/apps/files/ajax/download.php?${params}`)

    window.location.href = `${downloadURL}downloadStartSecret=${randomToken}`

    return new Promise((resolve) => {
        const waitForCookieInterval = setInterval(
            () => {
                const cookieIsSet = document.cookie
                    .split(';')
                    .map(cookie => cookie.split('='))
                    .findIndex(([cookieName, cookieValue]) => cookieName === 'ocDownloadStarted' && cookieValue === randomToken)

                if (cookieIsSet) {
                    clearInterval(waitForCookieInterval)
                    resolve(true)
                }
            },
            50
        )
    })
}

/**
 * Download the files given by the fileIds
 * @param fileIds list of file ids
 */
export async function downloadFilesByIds(fileIds: number[]) {
    if (fileIds.length === 0) {
        return;
    }

    // Get files to download
    const fileInfos = await getFiles(fileIds);
    await downloadFiles(fileInfos.map(f => f.filename));
}

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
        fileInfos = await getFiles(fileIds.filter(f => f));
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

    yield* runInParallel(calls, 10);
}

/**
 * Get the onThisDay data
 * Query for last 120 years; should be enough
 */
export async function getOnThisDayData(): Promise<IDay[]> {
    const diffs: { [dayId: number]: number } = {};
    const now = new Date();
    const nowUTC = new Date(now.getTime() - now.getTimezoneOffset() * 60000);

    // Populate dayIds
    for (let i = 1; i <= 120; i++) {
        // +- 3 days from this day
        for (let j = -3; j <= 3; j++) {
            const d = new Date(nowUTC);
            d.setFullYear(d.getFullYear() - i);
            d.setDate(d.getDate() + j);
            const dayId = Math.floor(d.getTime() / 1000 / 86400)
            diffs[dayId] = i;
        }
    }

    // Query for photos
    let data: IPhoto[] = [];
    try {
        const res = await axios.post<IPhoto[]>(generateUrl('/apps/memories/api/days'), {
            body_ids: Object.keys(diffs).join(','),
        });
        data = res.data;
    } catch (e) {
        throw e;
    }

    // Group photos by day
    const ans: IDay[] = [];
    const prevDayId = Number.MIN_SAFE_INTEGER;
    for (const photo of data) {
        if (!photo.dayid) continue;

        // This works because the response is sorted by date taken
        if (photo.dayid !== prevDayId) {
            ans.push({
                dayid: photo.dayid,
                count: 0,
                detail: [],
            });
        }

        // Add to last day
        const day = ans[ans.length - 1];
        day.detail.push(photo);
        day.count++;
    }

    return ans;
}

/**
 * Get list of tags and convert to Days response
 */
export async function getTagsData(): Promise<IDay[]> {
    // Query for photos
    let data: {
        id: number;
        count: number;
        name: string;
        previews: IPhoto[];
    }[] = [];
    try {
        const res = await axios.get<typeof data>(generateUrl('/apps/memories/api/tags'));
        data = res.data;
    } catch (e) {
        throw e;
    }

    // Add flag to previews
    data.forEach(t => t.previews?.forEach((preview) => preview.flag = 0));

    // Convert to days response
    return [{
        dayid: constants.TagDayID.TAGS,
        count: data.length,
        detail: data.map((tag) => ({
            ...tag,
            fileid: hashCode(tag.name),
            flag: constants.c.FLAG_IS_TAG,
            istag: true,
        } as ITag)),
    }]
}

/**
 * Get list of tags and convert to Days response
 */
 export async function getPeopleData(): Promise<IDay[]> {
    // Query for photos
    let data: {
        id: number;
        count: number;
        name: string;
        previews: IPhoto[];
    }[] = [];
    try {
        const res = await axios.get<typeof data>(generateUrl('/apps/memories/api/faces'));
        data = res.data;
    } catch (e) {
        throw e;
    }

    // Add flag to previews
    data.forEach(t => t.previews?.forEach((preview) => preview.flag = 0));

    // Convert to days response
    return [{
        dayid: constants.TagDayID.FACES,
        count: data.length,
        detail: data.map((face) => ({
            ...face,
            fileid: hashCode(face.name),
            faceid: face.id,
            istag: true,
            isface: true,
        } as any)),
    }]
}