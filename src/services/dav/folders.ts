import * as base from "./base";
import { getCurrentUser } from "@nextcloud/auth";
import { genFileInfo } from "../FileUtils";
import { IFileInfo } from "../../types";
import client from "../DavClient";

/**
 * Get file infos for files in folder path
 * @param folderPath Path to folder
 * @param limit Max number of files to return
 */
export async function getFolderPreviewFileIds(
  folderPath: string,
  limit: number
): Promise<IFileInfo[]> {
  const prefixPath = `/files/${getCurrentUser()?.uid}`;

  const filter = base.IMAGE_MIME_TYPES.map(
    (mime) => `
        <d:like>
            <d:prop>
                <d:getcontenttype/>
            </d:prop>
            <d:literal>${mime}</d:literal>
        </d:like>
    `
  ).join("");

  const options = {
    method: "SEARCH",
    headers: {
      "content-Type": "text/xml",
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
                            ${base.props}
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
    responseType: "text",
  };

  let response: any = await client.getDirectoryContents("", options);
  return response.data
    .map((data: any) => genFileInfo(data))
    .map((data: any) =>
      Object.assign({}, data, {
        filename: data.filename.replace(prefixPath, ""),
        etag: data.etag.replace(/&quot;/g, ""), // remove quotes
      })
    );
}
