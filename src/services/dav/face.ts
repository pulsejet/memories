import axios from '@nextcloud/axios';
import { showError } from '@nextcloud/dialogs';
import { translate as t } from '@nextcloud/l10n';
import { generateUrl } from '@nextcloud/router';
import { IFace, IPhoto } from '../../types';
import { API } from '../API';
import client from './client';
import { constants as c } from '../utils';
import * as base from './base';

/**
 * Get list of faces
 * @param app Backend app to use
 */
export async function getFaceList(app: 'recognize' | 'facerecognition') {
  return (await axios.get<IFace[]>(API.FACE_LIST(app))).data;
}

/**
 * Update a person or cluster in face recognition
 * @param name Name of face (or ID)
 * @param params Parameters to update
 */
export async function faceRecognitionUpdatePerson(name: string, params: object) {
  if (Number.isInteger(Number(name))) {
    return await axios.put(generateUrl(`/apps/facerecognition/api/2.0/cluster/${name}`), params);
  } else {
    return await axios.put(generateUrl(`/apps/facerecognition/api/2.0/person/${name}`), params);
  }
}

/**
 * Rename a face in face recognition
 * @param name Name of face (or ID)
 * @param target Target name of face
 */
export async function faceRecognitionRenamePerson(name: string, target: string) {
  return await faceRecognitionUpdatePerson(name, { name: target });
}

/**
 * Set visibility of a face
 * @param name Name of face (or ID)
 * @param visible Visibility of face
 */
export async function faceRecognitionSetPersonVisibility(name: string, visible: boolean) {
  return await faceRecognitionUpdatePerson(name, { visible });
}

/**
 * Remove images from a face.
 *
 * @param user User ID of face
 * @param name Name of face (or ID)
 * @param photos List of photos to remove
 * @returns Generator for face IDs
 */
export async function* recognizeDeleteFaceImages(user: string, name: string, photos: IPhoto[]) {
  // Remove each file
  const calls = photos.map((p) => async () => {
    try {
      await client.deleteFile(`/recognize/${user}/faces/${name}/${p.faceid}-${p.basename}`);
      return p.faceid!;
    } catch (e) {
      console.error(e);
      showError(
        t('memories', 'Failed to remove {filename} from face.', {
          filename: p.basename ?? p.fileid,
        }),
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
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
export async function* recognizeMoveFaceImages(user: string, face: string, target: string, photos: IPhoto[]) {
  // Remove each file
  const calls = photos.map((p) => async () => {
    try {
      const dest = `/recognize/${user}/faces/${target}`;
      const name = `${p.faceid}-${p.basename}`;

      // NULL source needs special handling
      let source = `/recognize/${user}/faces/${face}`;
      if (face === c.FACE_NULL) {
        source = `/recognize/${user}/unassigned-faces`;
      }

      await client.moveFile(`${source}/${name}`, `${dest}/${name}`);
      return p.faceid!;
    } catch (e) {
      console.error(e);
      showError(
        t('memories', 'Failed to move {filename} from face.', {
          filename: p.basename ?? p.fileid,
        }),
      );
      return 0;
    }
  });

  yield* base.runInParallel(calls, 10);
}

/**
 * Remove a face entirely
 *
 * @param user User ID of face
 * @param name Name of face (or ID)
 */
export async function recognizeDeleteFace(user: string, name: string) {
  return await client.deleteFile(`/recognize/${user}/faces/${name}`);
}

/**
 * Rename a face in recognize
 *
 * @param user User ID of face
 * @param name Name of face (or ID)
 * @param target Target name of face
 */
export async function recognizeRenameFace(user: string, name: string, target: string) {
  return await client.moveFile(`/recognize/${user}/faces/${name}`, `/recognize/${user}/faces/${target}`);
}

/**
 * Create a new face in recognize.
 */
export async function recognizeCreateFace(user: string, name: string) {
  return await client.createDirectory(`/recognize/${user}/faces/${name}`);
}
