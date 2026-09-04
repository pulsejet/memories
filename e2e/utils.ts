import { XMLParser } from 'fast-xml-parser';
import XMLBuilder from 'fast-xml-builder';
import type { APIRequestContext } from '@playwright/test';
import type { IImageInfo } from '@typings';
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

// WebDAV and API client for e2e tests.
export class DavClient {
  constructor(private request: APIRequestContext) {}

  // Delete the collection or file at the given path relative to the DAV root
  // using WebDAV DELETE, e.g. `photos/${username}/albums/${albumName}`.
  async del(davPath: string, ignoreMissing: boolean = false): Promise<void> {
    const cleanPath = psub(davPath).replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/');

    const res = await this.request.fetch(`${baseUrl}/remote.php/dav/${cleanPath}`, {
      method: 'DELETE',
      headers: e2eHeaders(),
    });
    if (!res.ok()) {
      if (ignoreMissing && res.status() === 404) {
        return;
      }
      throw new Error(`del DELETE failed for ${davPath} (${cleanPath}): ${res.status()} ${res.statusText()}`);
    }
  }

  // Copy from srcDavPath to dstDavPath, both relative to the DAV root,
  // using WebDAV COPY.
  async copy(srcDavPath: string, dstDavPath: string, overwrite: boolean = true): Promise<void> {
    const cleanSrc = psub(srcDavPath).replace(/^\/+/, '');
    const encodedSrc = cleanSrc.split('/').map(encodeURIComponent).join('/');
    const cleanDst = psub(dstDavPath).replace(/^\/+/, '');
    const encodedDst = cleanDst.split('/').map(encodeURIComponent).join('/');

    const res = await this.request.fetch(`${baseUrl}/remote.php/dav/${encodedSrc}`, {
      method: 'COPY',
      headers: {
        ...e2eHeaders(),
        Destination: `${baseUrl}/remote.php/dav/${encodedDst}`,
        Overwrite: overwrite ? 'T' : 'F',
      },
    });
    if (!res.ok()) {
      throw new Error(`copy COPY failed from ${srcDavPath} to ${dstDavPath}: ${res.status()} ${res.statusText()}`);
    }
  }

  // Get props via WebDAV PROPFIND on the given path relative to the DAV root,
  // e.g. `files/${username}/Photos/test.jpg`. Returns the list of found props.
  async propfind(
    davPath: string,
    props: Record<string, string>,
    depth: number | 'infinity' = 0,
  ): Promise<any[]> {
    const cleanPath = psub(davPath).replace(/^\/+/, '');
    const encodedPath = cleanPath.split('/').map(encodeURIComponent).join('/');
    const res = await this.request.fetch(`${baseUrl}/remote.php/dav/${encodedPath}`, {
      method: 'PROPFIND',
      headers: {
        ...e2eHeaders(),
        'Content-Type': 'application/xml',
        Depth: String(depth),
      },
      data: xmlBuilder.build({
        '?xml': {
          '@_version': '1.0',
          '@_encoding': 'UTF-8',
        },
        'd:propfind': {
          '@_xmlns:d': 'DAV:',
          '@_xmlns:oc': 'http://owncloud.org/ns',
          'd:prop': props,
        },
      }),
    });
    if (!res.ok()) {
      throw new Error(`propfind PROPFIND failed for ${davPath} (${cleanPath}): ${res.status()} ${res.statusText()}`);
    }
    const parsed = xmlParser.parse(await res.text());
    const responses = parsed?.multistatus?.response;
    const found: any[] = [];
    for (const r of Array.isArray(responses) ? responses : [responses]) {
      const propstats = Array.isArray(r?.propstat) ? r.propstat : [r?.propstat];
      for (const ps of propstats) {
        if (ps?.prop !== undefined) {
          found.push(ps.prop);
        }
      }
    }
    return found;
  }

  // Create a collection at the given path relative to the DAV root
  // using WebDAV MKCOL, e.g. `photos/${username}/albums/${albumName}`.
  async mkcol(davPath: string, ignoreExisting: boolean = false): Promise<void> {
    const cleanPath = psub(davPath).replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/');

    const res = await this.request.fetch(`${baseUrl}/remote.php/dav/${cleanPath}`, {
      method: 'MKCOL',
      headers: e2eHeaders(),
    });
    if (!res.ok()) {
      if (ignoreExisting && res.status() === 405) {
        return;
      }
      throw new Error(`mkcol MKCOL failed for ${davPath} (${cleanPath}): ${res.status()} ${res.statusText()}`);
    }
  }

  // Copy a file or folder from srcPath to dstPath by user file path using WebDAV COPY.
  async copyFile(srcPath: string, dstPath: string, overwrite: boolean = true): Promise<void> {
    await this.copy(
      `files/${username}/${srcPath.replace(/^\/+/, '')}`,
      `files/${username}/${dstPath.replace(/^\/+/, '')}`,
      overwrite,
    );
  }

  // Delete a file or folder by user file path using WebDAV DELETE.
  async deleteFile(targetPath: string, ignoreMissing: boolean = false): Promise<void> {
    await this.del(`files/${username}/${targetPath.replace(/^\/+/, '')}`, ignoreMissing);
  }

  // Get fileid for a photo by its user file path.
  async fileid(filePath: string): Promise<number> {
    const davPath = `files/${username}/${filePath.replace(/^\/+/, '')}`;
    const props = await this.propfind(davPath, { 'oc:fileid': '' });
    const fileid = props.find((p: any) => p?.fileid !== undefined)?.fileid;

    if (fileid === undefined || fileid === null) {
      throw new Error(`propfind: failed to parse fileid for ${davPath}`);
    }
    return parseInt(String(fileid), 10);
  }

  // Get image info by fileid from the image info endpoint.
  async imageInfo(
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
    const res = await this.request.get(url.toString(), {
      headers: e2eHeaders(),
    });
    if (!res.ok()) throw new Error(`imageInfo failed: ${res.status()}`);
    return res.json();
  }
}
