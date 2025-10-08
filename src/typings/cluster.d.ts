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
    cover?: number | null;
    /** ETag of cover object */
    cover_etag?: string;

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
    /** Etag of last added photo */
    last_added_photo_etag: string;
    /** Record ID of the latest update */
    update_id: number;
    /** Album is shared with other users */
    shared: boolean;
  }

  export interface IFace extends ICluster {
    /** User for face */
    user_id: string;
  }

  export interface IPlace extends ICluster {
    __p: never; // cannot have empty interface
  }

  export interface ITag extends ICluster {
    __t: never; // cannot have empty interface
  }

  export interface IEmbeddedTag {
    /** Unique identifier for the tag */
    id: number;
    /** User ID who owns the tag */
    user_id: string;
    /** Tag name (leaf node) */
    tag: string;
    /** Parent tag ID for hierarchy */
    parent_tag_id?: number | null;
    /** Full path of the tag */
    path: string;
    /** Level in hierarchy (0 for root) */
    level: number;
    /** Creation timestamp */
    created_at: string;
    /** Children tags (for hierarchical structure) */
    children?: IEmbeddedTag[];
  }

  export interface IEmbeddedTagsResponse {
    /** Array of tags */
    tags: IEmbeddedTag[];
    /** Structure type indicator */
    structure?: 'flat' | 'hierarchical';
    /** Pagination info for flat responses */
    pagination?: {
      total: number;
      limit: number | null;
      offset: number;
    };
  }

  export interface IEmbeddedTagsCountResponse {
    /** Count of matching tags */
    count: number;
    /** Pattern used for filtering */
    pattern: string | null;
  }

  export interface IFaceRect {
    w: number;
    h: number;
    x: number;
    y: number;
  }
}
