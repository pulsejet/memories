import { API } from '@services/API';
import { getPreviewUrl } from '@services/utils';

import type { IAlbum, ICluster, IFace, IPhoto, IPlace, ITag } from '@typings';

export const clusterIs = {
  album: (cluster: ICluster): cluster is IAlbum => cluster.cluster_type === 'albums',
  place: (cluster: ICluster): cluster is IPlace => cluster.cluster_type === 'places',
  tag: (cluster: ICluster): cluster is ITag => cluster.cluster_type === 'tags',
  recognize: (cluster: ICluster): cluster is IFace => cluster.cluster_type === 'recognize',
  facerecognition: (cluster: ICluster): cluster is IFace => cluster.cluster_type === 'facerecognition',
  face: (cluster: ICluster): cluster is IFace =>
    cluster.cluster_type === 'recognize' || cluster.cluster_type === 'facerecognition',
};

/**
 * Get the preview URL for a cluster
 * @param cluster Cluster object
 */
export function getClusterPreview(cluster: ICluster, size = 512) {
  // Helper to get preview URL from fileid and etag
  const preview = (fileid: number, etag: string | number) =>
    getPreviewUrl({
      photo: {
        fileid: fileid,
        etag: etag.toString(),
      } as IPhoto,
      sqsize: 512,
    });

  // If a cover is fileid, directly use it if we don't need crop
  // Use the cover etag here since we forced a random cover below
  if (cluster.cover && cluster.cover_etag && !clusterIs.face(cluster)) {
    return preview(cluster.cover, cluster.cover_etag);
  }

  if (clusterIs.album(cluster)) {
    // Always fall back to last update for albums
    // Never go to CLUSTER_PREVIEW since it is not fully implemented
    return preview(cluster.last_added_photo, cluster.last_added_photo_etag ?? cluster.album_id);
  }

  // Force a cover if not set
  cluster.cover ??= Math.random();

  // Use a random cover ID to bust local cache
  return API.CLUSTER_PREVIEW(cluster.cluster_type, cluster.cluster_id, cluster.cover, cluster.cover_etag ?? 'null');
}

/**
 * Get the target route name and params for the cluster
 * @param cluster Cluster object
 * @returns {string} The target route name and params for the cluster
 */
export function getClusterLinkTarget(cluster: ICluster) {
  if (clusterIs.album(cluster)) {
    const { user, name } = cluster;
    return { name: _m.routes.Albums.name, params: { user, name } };
  }

  if (clusterIs.face(cluster)) {
    const name = String(cluster.name || cluster.cluster_id);
    const user = cluster.user_id;
    return { name: cluster.cluster_type, params: { name, user } };
  }

  if (clusterIs.place(cluster)) {
    const id = cluster.cluster_id;
    const placeName = cluster.name || id;
    const name = `${id}-${placeName}`;
    return { name: _m.routes.Places.name, params: { name } };
  }

  if (clusterIs.tag(cluster)) {
    return { name: _m.routes.Tags.name, params: { name: cluster.name } };
  }

  return {};
}
