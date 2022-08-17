import { getCurrentUser } from '@nextcloud/auth'
import { genFileInfo } from './FileUtils'
import client from './DavClient';

const props = `
	<oc:fileid />
	<d:getlastmodified />
	<d:getetag />
	<d:getcontenttype />
	<d:getcontentlength />
	<nc:has-preview />
	<oc:favorite />
	<d:resourcetype />`;

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
