import { XMLParser } from 'fast-xml-parser';
import XMLBuilder from 'fast-xml-builder';
import type { APIRequestContext } from '@playwright/test';
import type { IImageInfo, IPhoto } from '@typings';
import { appUrl, baseUrl, e2eHeaders, username, psub } from './navigation';

const xmlParser = new XMLParser({
  removeNSPrefix: true,
  ignoreAttributes: true,
});

const xmlBuilder = new XMLBuilder({
  ignoreAttributes: false,
  format: true,
  suppressEmptyNode: true,
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
export async function getImageInfo(
  request: APIRequestContext,
  fileid: number,
  params?: {
    basic?: string;
    current?: string;
    tags?: string;
    clusters?: string;
  },
): Promise<IImageInfo> {
  const url = new URL(`${appUrl}/api/image/info/${fileid}?`);
  for (const [k, v] of Object.entries(params || {})) {
    url.searchParams.set(k, v);
  }
  const res = await request.get(url.toString(), {
    headers: e2eHeaders(),
  });
  if (!res.ok()) throw new Error(`getImageInfo failed: ${res.status()}`);
  return res.json();
}

// Get fileid for a photo by its full path using WebDAV PROPFIND.
export async function getFileId(request: APIRequestContext, filePath: string): Promise<number> {
  const cleanPath = psub(filePath).replace(/^\/+/, '');
  const encodedPath = cleanPath.split('/').map(encodeURIComponent).join('/');
  const res = await request.fetch(`${baseUrl}/remote.php/dav/files/${username}/${encodedPath}`, {
    method: 'PROPFIND',
    headers: {
      ...e2eHeaders(),
      'Content-Type': 'application/xml',
      Depth: '0',
    },
    data: xmlBuilder.build({
      '?xml': {
        '@_version': '1.0',
        '@_encoding': 'UTF-8',
      },
      'd:propfind': {
        '@_xmlns:d': 'DAV:',
        '@_xmlns:oc': 'http://owncloud.org/ns',
        'd:prop': {
          'oc:fileid': '',
        },
      },
    }),
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

// Copy a file or folder from srcPath to dstPath using WebDAV COPY.
export async function copyPath(
  request: APIRequestContext,
  srcPath: string,
  dstPath: string,
  overwrite: boolean = true,
): Promise<void> {
  const cleanSrc = psub(srcPath).replace(/^\/+/, '');
  const encodedSrc = cleanSrc.split('/').map(encodeURIComponent).join('/');
  const cleanDst = psub(dstPath).replace(/^\/+/, '');
  const encodedDst = cleanDst.split('/').map(encodeURIComponent).join('/');

  const res = await request.fetch(`${baseUrl}/remote.php/dav/files/${username}/${encodedSrc}`, {
    method: 'COPY',
    headers: {
      ...e2eHeaders(),
      Destination: `${baseUrl}/remote.php/dav/files/${username}/${encodedDst}`,
      Overwrite: overwrite ? 'T' : 'F',
    },
  });
  if (!res.ok()) {
    throw new Error(`copyPath COPY failed from ${srcPath} to ${dstPath}: ${res.status()} ${res.statusText()}`);
  }
}

// Delete a file or folder by path using WebDAV DELETE.
export async function deletePath(
  request: APIRequestContext,
  targetPath: string,
  ignoreMissing: boolean = false,
): Promise<void> {
  const cleanPath = psub(targetPath).replace(/^\/+/, '');
  const encodedPath = cleanPath.split('/').map(encodeURIComponent).join('/');

  const res = await request.fetch(`${baseUrl}/remote.php/dav/files/${username}/${encodedPath}`, {
    method: 'DELETE',
    headers: e2eHeaders(),
  });
  if (!res.ok()) {
    if (ignoreMissing && res.status() === 404) {
      return;
    }
    throw new Error(`deletePath DELETE failed for ${targetPath} (${cleanPath}): ${res.status()} ${res.statusText()}`);
  }
}
