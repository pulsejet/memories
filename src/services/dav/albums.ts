import * as base from "./base";
import { getCurrentUser } from "@nextcloud/auth";
import { generateUrl } from "@nextcloud/router";
import { showError } from "@nextcloud/dialogs";
import { translate as t, translatePlural as n } from "@nextcloud/l10n";
import { IAlbum, IDay, ITag } from "../../types";
import { constants } from "../Utils";
import axios from "@nextcloud/axios";
import client from "../DavClient";

/**
 * Get DAV path for album
 */
export function getAlbumPath(user: string, name: string) {
  // Folder in the dav collection for user
  const cuid = getCurrentUser().uid;
  if (user === cuid) {
    return `/photos/${cuid}/albums/${name}`;
  } else {
    return `/photos/${cuid}/sharedalbums/${name} (${user})`;
  }
}

/**
 * Get list of albums and convert to Days response
 * @param type Type of albums to get; 1 = personal, 2 = shared, 3 = all
 */
export async function getAlbumsData(type: "1" | "2" | "3"): Promise<IDay[]> {
  let data: IAlbum[] = [];
  try {
    const res = await axios.get<typeof data>(
      generateUrl(`/apps/memories/api/albums?t=${type}`)
    );
    data = res.data;
  } catch (e) {
    throw e;
  }

  // Convert to days response
  return [
    {
      dayid: constants.TagDayID.ALBUMS,
      count: data.length,
      detail: data.map(
        (album) =>
          ({
            ...album,
            fileid: album.album_id,
            flag: constants.c.FLAG_IS_TAG & constants.c.FLAG_IS_ALBUM,
            istag: true,
            isalbum: true,
          } as ITag)
      ),
    },
  ];
}

/**
 * Add photos to an album.
 *
 * @param user User ID of album
 * @param name Name of album (or ID)
 * @param fileIds List of file IDs to add
 * @returns Generator
 */
export async function* addToAlbum(
  user: string,
  name: string,
  fileIds: number[]
) {
  // Get files data
  let fileInfos = await base.getFiles(fileIds.filter((f) => f));

  const albumPath = getAlbumPath(user, name);

  // Add each file
  const calls = fileInfos.map((f) => async () => {
    try {
      await client.copyFile(f.originalFilename, `${albumPath}/${f.basename}`);
      return f.fileid;
    } catch (e) {
      if (e.response?.status === 409) {
        // File already exists, all good
        return f.fileid;
      }

      showError(
        t("memories", "Failed to add {filename} to album.", {
          filename: f.filename,
        })
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}

/**
 * Remove photos from an album.
 *
 * @param user Owner of album
 * @param name Name of album (or ID)
 * @param fileIds List of file IDs to remove
 * @returns Generator
 */
export async function* removeFromAlbum(
  user: string,
  name: string,
  fileIds: number[]
) {
  // Get files data
  let fileInfos = await base.getFiles(fileIds.filter((f) => f));

  // Add each file
  const calls = fileInfos.map((f) => async () => {
    try {
      await client.deleteFile(
        `/photos/${user}/albums/${name}/${f.fileid}-${f.basename}`
      );
      return f.fileid;
    } catch (e) {
      showError(
        t("memories", "Failed to remove {filename}.", {
          filename: f.filename,
        })
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}

/**
 * Create an album.
 */
export async function createAlbum(albumName: string) {
  try {
    await client.createDirectory(
      `/photos/${getCurrentUser()?.uid}/albums/${albumName}`
    );
  } catch (error) {
    console.error(error);
    showError(t("photos", "Failed to create {albumName}.", { albumName }));
  }
}

/**
 * Update an album's properties.
 *
 * @param {object} album Album to update
 * @param {object} data destructuring object
 * @param {string} data.albumName - The name of the album.
 * @param {object} data.properties - The properties to update.
 */
export async function updateAlbum(album: any, { albumName, properties }: any) {
  const stringifiedProperties = Object.entries(properties)
    .map(([name, value]) => {
      switch (typeof value) {
        case "string":
          return `<nc:${name}>${value}</nc:${name}>`;
        case "object":
          return `<nc:${name}>${JSON.stringify(value)}</nc:${name}>`;
        default:
          return "";
      }
    })
    .join();

  try {
    await client.customRequest(album.filename, {
      method: "PROPPATCH",
      data: `<?xml version="1.0"?>
                        <d:propertyupdate xmlns:d="DAV:"
                            xmlns:oc="http://owncloud.org/ns"
                            xmlns:nc="http://nextcloud.org/ns"
                            xmlns:ocs="http://open-collaboration-services.org/ns">
                        <d:set>
                            <d:prop>
                                ${stringifiedProperties}
                            </d:prop>
                        </d:set>
                        </d:propertyupdate>`,
    });

    return album;
  } catch (error) {
    console.error(error);
    showError(
      t(
        "photos",
        "Failed to update properties of {albumName} with {properties}.",
        { albumName, properties: JSON.stringify(properties) }
      )
    );
    return album;
  }
}

/**
 * Get one album from DAV collection
 * @param user Owner of album
 * @param name Name of album (or ID)
 */
export async function getAlbum(user: string, name: string, extraProps = {}) {
  const req = `<?xml version="1.0"?>
        <d:propfind xmlns:d="DAV:"
            xmlns:oc="http://owncloud.org/ns"
            xmlns:nc="http://nextcloud.org/ns"
            xmlns:ocs="http://open-collaboration-services.org/ns">
            <d:prop>
                <nc:last-photo />
                <nc:nbItems />
                <nc:location />
                <nc:dateRange />
                <nc:collaborators />
                ${extraProps}
            </d:prop>
        </d:propfind>`;
  let album = (await client.stat(`/photos/${user}/albums/${name}`, {
    data: req,
    details: true,
  })) as any;

  // Post processing
  album = {
    ...album.data,
    ...album.data.props,
  };
  const c = album?.collaborators?.collaborator;
  album.collaborators = c ? (Array.isArray(c) ? c : [c]) : [];
  return album;
}

/** Rename an album */
export async function renameAlbum(
  album: any,
  { currentAlbumName, newAlbumName }
) {
  const newAlbum = { ...album, basename: newAlbumName };
  try {
    await client.moveFile(
      `/photos/${getCurrentUser()?.uid}/albums/${currentAlbumName}`,
      `/photos/${getCurrentUser()?.uid}/albums/${newAlbumName}`
    );
    return newAlbum;
  } catch (error) {
    console.error(error);
    showError(
      t("photos", "Failed to rename {currentAlbumName} to {newAlbumName}.", {
        currentAlbumName,
        newAlbumName,
      })
    );
    return album;
  }
}
