import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { genFileInfo } from './FileUtils'
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

export async function getFiles(fileIds) {
    const prefixPath = `/files/${getCurrentUser().uid}`;

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

    let response = await client.getDirectoryContents('', options);
    return response.data
		.map(data => genFileInfo(data))
		.map(data => Object.assign({}, data, { filename: data.filename.replace(prefixPath, '') }));
}

export async function getFolderPreviewFileIds(folderPath, limit) {
    const prefixPath = `/files/${getCurrentUser().uid}`;

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

    let response = await client.getDirectoryContents('', options);
    return response.data
		.map(data => genFileInfo(data))
		.map(data => Object.assign({}, data, {
			filename: data.filename.replace(prefixPath, '')
		}));
}

export async function deleteFile(path) {
    const prefixPath = `/files/${getCurrentUser().uid}`;
    return await client.deleteFile(`${prefixPath}${path}`);
}

/**
 * Download a file
 *
 * @param {string[]} fileNames - The file's names
 */
 export async function downloadFiles(fileNames) {
	const randomToken = Math.random().toString(36).substring(2)

	const params = new URLSearchParams()
	params.append('files', JSON.stringify(fileNames))
	params.append('downloadStartSecret', randomToken)

	const downloadURL = generateUrl(`/apps/files/ajax/download.php?${params}`)

	window.location = `${downloadURL}downloadStartSecret=${randomToken}`

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
