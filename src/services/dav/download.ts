import * as base from './base';
import { generateUrl } from '@nextcloud/router'

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
    const fileInfos = await base.getFiles(fileIds);
    await downloadFiles(fileInfos.map(f => f.filename));
}