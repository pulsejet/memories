import axios from '@nextcloud/axios';
import { showError } from '@services/utils/dialog';
import { generateUrl } from '@nextcloud/router';

import client from './client';
import * as base from './base';

import { translate as t } from '@services/l10n';
import { constants } from '@services/constants';
import { API } from '@services/API';

import type { IFace, IPhoto } from '@typings';

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
      if (face === constants.FACE_NULL) {
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

/** Where a face on a photo came from, as the server derives it. */
export type FaceOrigin = 'auto' | 'manual';

/** Whether a face takes part in the automatic clustering. */
export type FaceClustering = 'participating' | 'pending' | 'excluded';

/** Why a face does not take part in the automatic clustering. */
export type FaceExcludedReason = 'too_small' | 'low_confidence' | 'no_face' | 'detached' | 'ignored';

/** How the search of a face marked by hand ended. */
export type ManualFaceState = 'pending' | 'found' | 'no_face' | 'confirmed';

/**
 * One face rectangle on a photo, in original-image pixel coordinates.
 *
 * The state fields are null when the server does not know them, and they are
 * kept as plain strings: a newer server may send values this client does not
 * know, which must be shown as unknown rather than break anything.
 */
export type IFaceRectForFile = {
  id: number;
  x: number;
  y: number;
  width: number;
  height: number;
  cluster: number | null;
  personName: string | null;
  isManual: boolean;
  confidence: number | null;
  manualState: ManualFaceState | string | null;
  boxAdjusted: boolean | null;
  origin: FaceOrigin | string | null;
  clustering: FaceClustering | string | null;
  excludedReason: FaceExcludedReason | string | null;
  /** Number of faces of the group, null without a group or when unknown */
  clusterSize: number | null;
  /** Ignored by the user; null when the server does not say */
  ignored: boolean | null;
};

/** A region of a photo queued to be searched for faces again. */
export type IManualRegion = {
  id: number;
  x: number;
  y: number;
  width: number;
  height: number;
  state: 'pending' | 'done' | 'failed' | string;
  foundCount: number;
  tooSmallCount: number;
  lowConfidenceCount: number;
  error: string | null;
};

/** The minimums a face has to meet to take part in the clustering. */
export type IFaceLimits = {
  minFaceSize: number;
  minConfidence: number;
};

export type IFacesForFile = {
  faces: IFaceRectForFile[];
  /** Null when the regions are not available on the server */
  regions: IManualRegion[] | null;
  limits: IFaceLimits | null;
  /** The server answered in the old form, without any of the state */
  legacy: boolean;
};

const numberOrNull = (value: unknown): number | null =>
  value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? null : Number(value);

const stringOrNull = (value: unknown): string | null => (value === null || value === undefined ? null : String(value));

const booleanOrNull = (value: unknown): boolean | null =>
  value === null || value === undefined ? null : Boolean(value);

/** What comes over the wire: the fields of T, each of them missing or of any type. */
type Wire<T> = { [K in keyof T]?: unknown };

/** An object from the server as its wire shape, or an empty one for anything else. */
const wire = <T>(value: unknown): Wire<T> => (typeof value === 'object' && value !== null ? value : {}) as Wire<T>;

function toFace(value: unknown): IFaceRectForFile {
  const raw = wire<IFaceRectForFile>(value);
  return {
    id: Number(raw.id),
    x: Number(raw.x) || 0,
    y: Number(raw.y) || 0,
    width: Number(raw.width) || 0,
    height: Number(raw.height) || 0,
    cluster: numberOrNull(raw.cluster),
    personName: stringOrNull(raw.personName),
    isManual: Boolean(raw.isManual),
    confidence: numberOrNull(raw.confidence),
    manualState: stringOrNull(raw.manualState),
    boxAdjusted: booleanOrNull(raw.boxAdjusted),
    origin: stringOrNull(raw.origin),
    clustering: stringOrNull(raw.clustering),
    excludedReason: stringOrNull(raw.excludedReason),
    clusterSize: numberOrNull(raw.clusterSize),
    ignored: booleanOrNull(raw.ignored),
  };
}

function toRegion(value: unknown): IManualRegion {
  const raw = wire<IManualRegion>(value);
  return {
    id: Number(raw.id),
    x: Number(raw.x) || 0,
    y: Number(raw.y) || 0,
    width: Number(raw.width) || 0,
    height: Number(raw.height) || 0,
    state: String(raw.state ?? ''),
    foundCount: Number(raw.foundCount) || 0,
    tooSmallCount: Number(raw.tooSmallCount) || 0,
    lowConfidenceCount: Number(raw.lowConfidenceCount) || 0,
    error: stringOrNull(raw.error),
  };
}

/**
 * Reads the answer of the server about the faces of a file, in whatever form
 * it comes: the old one is a bare array of faces, without any of the state.
 */
export function normalizeFacesForFile(data: unknown): IFacesForFile {
  if (Array.isArray(data)) {
    return { faces: data.map(toFace), regions: null, limits: null, legacy: true };
  }

  const body = wire<{ faces: unknown; regions: unknown; limits: unknown }>(data);
  if (!Array.isArray(body.faces)) {
    throw new Error('Unexpected answer from Face Recognition');
  }

  const limits = wire<IFaceLimits>(body.limits);
  const minFaceSize = numberOrNull(limits.minFaceSize);
  const minConfidence = numberOrNull(limits.minConfidence);

  return {
    faces: body.faces.map(toFace),
    regions: Array.isArray(body.regions) ? body.regions.map(toRegion) : null,
    limits: minFaceSize !== null && minConfidence !== null ? { minFaceSize, minConfidence } : null,
    legacy: false,
  };
}

/**
 * Fetch all known face rectangles for a single file (face recognition app),
 * with their state, the regions queued on it and the minimums of the
 * clustering. Used by the manual-face dialog.
 */
export async function faceRecognitionGetFacesForFile(fileId: number): Promise<IFacesForFile> {
  const url = generateUrl(`/apps/facerecognition/api/2.0/file/${fileId}/faces`);
  return normalizeFacesForFile((await axios.get<unknown>(url)).data);
}

/** A rectangle as fractions 0..1 of a photo, and the natural size of the photo. */
type IManualRect = {
  fileId: number;
  x: number;
  y: number;
  width: number;
  height: number;
  imageWidth: number;
  imageHeight: number;
};

/**
 * Create a manually drawn face on a photo. With a name it is attached to that
 * (possibly new) person; with an empty name it is left for the clustering to
 * place. The server searches the marked region for a face in the background.
 */
export async function faceRecognitionAddManualFace(params: IManualRect & { personName: string }) {
  const url = generateUrl(`/apps/facerecognition/api/2.0/face/manual`);
  return (
    await axios.post<{
      faceId: number;
      clusterId: number | null;
      personId: number | null;
      name: string | null;
      manualState: string;
    }>(url, {
      fileId: params.fileId,
      personName: params.personName,
      x: params.x,
      y: params.y,
      width: params.width,
      height: params.height,
      imageWidth: params.imageWidth,
      imageHeight: params.imageHeight,
    })
  ).data;
}

/**
 * Queue a region of a photo to be searched for faces again by the background
 * job. Every face found there is created, and left for the clustering to place.
 */
export async function faceRecognitionAddManualRegion(params: IManualRect) {
  const url = generateUrl(`/apps/facerecognition/api/2.0/face/region`);
  return (
    await axios.post<{ regionId: number; state: string }>(url, {
      fileId: params.fileId,
      x: params.x,
      y: params.y,
      width: params.width,
      height: params.height,
      imageWidth: params.imageWidth,
      imageHeight: params.imageHeight,
    })
  ).data;
}

/**
 * Name a face that is in no group yet, such as a marking saved without a
 * name: it gets a group of its own for that person, like a marking saved with
 * the name. A face in a group is renamed with faceRecognitionAssignCluster.
 */
export async function faceRecognitionNameFace(faceId: number, name: string) {
  const url = generateUrl(`/apps/facerecognition/api/2.0/face/${faceId}/name`);
  return (await axios.put<{ faceId: number; clusterId: number; personId: number; name: string }>(url, { name })).data;
}

/**
 * Delete faces put there by hand: markings, and the faces found in a searched
 * area. The server refuses the whole request if one of them is a face of the
 * analysis, which can only be ignored.
 */
export async function faceRecognitionDeleteFaces(faceIds: number[]) {
  const url = generateUrl(`/apps/facerecognition/api/2.0/faces/delete`);
  return (await axios.post<{ faceIds: number[] }>(url, { faceIds })).data;
}

/**
 * Ignore faces: they stay on the photo, but are nobody and take no part in
 * the recognition. Faces ignored already are left as they are.
 */
export async function faceRecognitionIgnoreFaces(faceIds: number[]) {
  const url = generateUrl(`/apps/facerecognition/api/2.0/faces/ignore`);
  return (await axios.post<{ faceIds: number[] }>(url, { faceIds })).data;
}

/**
 * Delete areas drawn to be searched. One still waiting is not searched any
 * more; the faces found in the others stay.
 */
export async function faceRecognitionDeleteRegions(regionIds: number[]) {
  const url = generateUrl(`/apps/facerecognition/api/2.0/regions/delete`);
  return (await axios.post<{ regionIds: number[] }>(url, { regionIds })).data;
}

/** Stop ignoring faces: they go back to the recognition. */
export async function faceRecognitionUnignoreFaces(faceIds: number[]) {
  const url = generateUrl(`/apps/facerecognition/api/2.0/faces/unignore`);
  return (await axios.post<{ faceIds: number[] }>(url, { faceIds })).data;
}

/**
 * Assign a group (cluster) to a person. With a face, only that face is moved
 * to the person and the other faces of the group stay where they are; without
 * one, the whole group goes to the person.
 */
export async function faceRecognitionAssignCluster(clusterId: number, name: string, faceId?: number) {
  const url = generateUrl(`/apps/facerecognition/cluster/${clusterId}`);
  const params = faceId === undefined ? { name } : { name, face_id: faceId };
  return (await axios.put<{ id: number; name: string | null; is_visible: boolean }>(url, params)).data;
}
