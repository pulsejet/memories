import { XMLParser } from 'fast-xml-parser';
import type { APIRequestContext } from '@playwright/test';
import type { IImageInfo, IPhoto } from '@typings';
import { appUrl, baseUrl, ocsHeaders, username } from './navigation';

const xmlParser = new XMLParser({
  removeNSPrefix: true,
  ignoreAttributes: true,
});

// Cleanup unpredictable values from photo object.
export function cleanupPhoto(item: IPhoto): void {
  if (typeof item.etag !== 'string' || item.etag.length === 0) {
    throw new Error(`Invalid etag: ${item.etag}`);
  }
  if (typeof item.fileid !== 'number' || item.fileid <= 0) {
    throw new Error(`Invalid fileid: ${item.fileid}`);
  }

  delete item.etag;
  item.fileid = 0;
  item.flag = 0;
}

// Get image info by fileid from the image info endpoint.
export async function getImageInfo(request: APIRequestContext, fileid: number): Promise<IImageInfo> {
  const res = await request.get(`${appUrl}/api/image/info/${fileid}`, {
    headers: ocsHeaders,
  });
  if (!res.ok()) throw new Error(`getImageInfo failed: ${res.status()}`);
  return res.json();
}

// Get fileid for a photo by its full path using WebDAV PROPFIND.
export async function getFileId(request: APIRequestContext, filePath: string): Promise<number> {
  const cleanPath = filePath.replace(/^\/+/, '');
  const encodedPath = cleanPath.split('/').map(encodeURIComponent).join('/');
  const res = await request.fetch(`${baseUrl}/remote.php/dav/files/${username}/${encodedPath}`, {
    method: 'PROPFIND',
    headers: {
      ...ocsHeaders,
      'Content-Type': 'application/xml',
      Depth: '0',
    },
    data: `<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:fileid />
  </d:prop>
</d:propfind>`,
  });
  if (!res.ok()) {
    throw new Error(`getFileId PROPFIND failed for ${filePath} (${cleanPath}): ${res.status()} ${res.statusText()}`);
  }
  const xml = await res.text();
  const parsed = xmlParser.parse(xml);
  const response = parsed?.multistatus?.response;
  const propstats = Array.isArray(response?.propstat) ? response.propstat : [response?.propstat];
  const fileid = propstats.find((ps: any) => ps?.prop?.fileid !== undefined)?.prop?.fileid;

  if (fileid === undefined || fileid === null) {
    throw new Error(`getFileId: failed to parse fileid for ${filePath} ` + `from WebDAV response:\n${xml}`);
  }
  return parseInt(String(fileid), 10);
}
