declare module '@typings' {
  export interface IFolder extends IPhoto {
    /** Photos for preview images */
    previews?: IPhoto[];
    /** Name of folder */
    name: string;
  }

  export type ClusterTypes = 'tags' | 'albums' | 'places' | 'recognize' | 'facerecognition' | 'plus';

  export interface ICluster {
    /** A unique identifier for the cluster */
    cluster_id: number | string;
    /** Type of cluster */
    cluster_type: ClusterTypes;
    /** Number of images in this cluster */
    count: number;
    /** Name of cluster */
    name: string;
    /** Object ID of cover object */
    cover: number;

    /** Display name, e.g. translated */
    display_name?: string;
    /** Preview loading failed */
    previewError?: boolean;
  }

  export interface IAlbum extends ICluster {
    /** ID of album */
    album_id: number;
    /** Owner of album */
    user: string;
    /** Display name of album owner */
    user_display?: string;
    /** Created timestamp */
    created: number;
    /** Location string */
    location: string;
    /** File ID of last added photo */
    last_added_photo: number;
  }

  export interface IFace extends ICluster {
    /** User for face */
    user_id: string;
  }

  export interface IFaceRect {
    w: number;
    h: number;
    x: number;
    y: number;
  }
}
