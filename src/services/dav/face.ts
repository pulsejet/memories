import axios from '@nextcloud/axios';
import { showError } from '@services/utils/dialog';
import { generateUrl } from '@nextcloud/router';

import client from './client';
import * as base from './base';

import { translate as t } from '@services/l10n';
import { constants } from '@services/constants';
import { API } from '@services/API';
import { isAbortError } from '@services/utils/abort';

import type { IFace, IPhoto } from '@typings';
import type { AbortOpts } from '@services/utils/abort';

/**
 * Get list of faces
 * @param app Backend app to use
 */
export async function getFaceList(app: 'recognize' | 'facerecognition', opts?: AbortOpts) {
  const signal = opts?.signal;
  return (await axios.get<IFace[]>(API.FACE_LIST(app), { signal })).data;
}

/**
 * Update a person or cluster in face recognition
 * @param name Name of face (or ID)
 * @param params Parameters to update
 */
export async function faceRecognitionUpdatePerson(name: string, params: object, opts?: AbortOpts) {
  if (Number.isInteger(Number(name))) {
    return await axios.put(generateUrl(`/apps/facerecognition/api/2.0/cluster/${name}`), params, {
      signal: opts?.signal,
    });
  } else {
    return await axios.put(generateUrl(`/apps/facerecognition/api/2.0/person/${name}`), params, {
      signal: opts?.signal,
    });
  }
}

/**
 * Rename a face in face recognition
 * @param name Name of face (or ID)
 * @param target Target name of face
 */
export async function faceRecognitionRenamePerson(name: string, target: string, opts?: AbortOpts) {
  return await faceRecognitionUpdatePerson(name, { name: target }, opts);
}

/**
 * Set visibility of a face
 * @param name Name of face (or ID)
 * @param visible Visibility of face
 */
export async function faceRecognitionSetPersonVisibility(name: string, visible: boolean, opts?: AbortOpts) {
  return await faceRecognitionUpdatePerson(name, { visible }, opts);
}

/**
 * Remove images from a face.
 *
 * @param user User ID of face
 * @param name Name of face (or ID)
 * @param photos List of photos to remove
 * @returns Generator for face IDs
 */
export async function* recognizeDeleteFaceImages(user: string, name: string, photos: IPhoto[], opts?: AbortOpts) {
  // Remove each file
  const calls = photos.map((p) => async () => {
    try {
      opts?.signal?.throwIfAborted();
      await client.deleteFile(`/recognize/${user}/faces/${name}/${p.faceid}-${p.basename}`, {
        signal: opts?.signal,
      });
      return p.faceid!;
    } catch (e) {
      if (isAbortError(e)) throw e;
      console.error(e);
      showError(
        t('memories', 'Failed to remove {filename} from face.', {
          filename: p.basename ?? p.fileid,
        }),
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10, opts);
}

/**
 * Move faces from one face to another
 *
 * @param user User ID of face
 * @param face Name of face (or ID)
 * @param target Name of target face (or ID)
 * @param photos List of photos to move
 * @returns Generator for face IDs
 */
export async function* recognizeMoveFaceImages(
  user: string,
  face: string,
  target: string,
  photos: IPhoto[],
  opts?: AbortOpts,
) {
  // Remove each file
  const calls = photos.map((p) => async () => {
    try {
      opts?.signal?.throwIfAborted();
      const dest = `/recognize/${user}/faces/${target}`;
      const name = `${p.faceid}-${p.basename}`;

      // NULL source needs special handling
      let source = `/recognize/${user}/faces/${face}`;
      if (face === constants.FACE_NULL) {
        source = `/recognize/${user}/unassigned-faces`;
      }

      await client.moveFile(`${source}/${name}`, `${dest}/${name}`, {
        signal: opts?.signal,
      });
      return p.faceid!;
    } catch (e) {
      if (isAbortError(e)) throw e;
      console.error(e);
      showError(
        t('memories', 'Failed to move {filename} from face.', {
          filename: p.basename ?? p.fileid,
        }),
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10, opts);
}

/**
 * Remove a face entirely
 *
 * @param user User ID of face
 * @param name Name of face (or ID)
 */
export async function recognizeDeleteFace(user: string, name: string, opts?: AbortOpts) {
  return await client.deleteFile(`/recognize/${user}/faces/${name}`, {
    signal: opts?.signal,
  });
}

/**
 * Rename a face in recognize
 *
 * @param user User ID of face
 * @param name Name of face (or ID)
 * @param target Target name of face
 */
export async function recognizeRenameFace(user: string, name: string, target: string, opts?: AbortOpts) {
  return await client.moveFile(`/recognize/${user}/faces/${name}`, `/recognize/${user}/faces/${target}`, {
    signal: opts?.signal,
  });
}

/**
 * Create a new face in recognize.
 */
export async function recognizeCreateFace(user: string, name: string, opts?: AbortOpts) {
  return await client.createDirectory(`/recognize/${user}/faces/${name}`, {
    signal: opts?.signal,
  });
}
