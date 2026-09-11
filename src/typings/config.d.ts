declare module '@typings' {
  type HighResCond = 'always' | 'zoom' | 'never';

  export type IConfig = {
    // general stuff
    version: string;
    vod_disable: boolean;
    video_default_quality: string;
    places_gis: number;
    places_search_url: string;
    map_tile_servers: IMapTileServer[];
    map_tile_server_url: string;
    language: string;
    locale: string;

    // enabled apps
    systemtags_enabled: boolean;
    albums_enabled: boolean;
    recognize_installed: boolean;
    recognize_enabled: boolean;
    facerecognition_installed: boolean;
    facerecognition_enabled: boolean;
    preview_generator_enabled: boolean;

    // general settings
    timeline_path: string;
    enable_top_memories: boolean;
    stack_raw_files: boolean;
    dedup_identical: boolean;
    show_owner_name_timeline: boolean;

    // viewer settings
    high_res_cond_default: HighResCond;
    livephoto_autoplay: boolean;
    livephoto_loop: boolean;
    video_loop: boolean;
    sidebar_filepath: boolean;
    metadata_in_slideshow: boolean;
    slideshow_duration: number;

    // on this day settings
    onthisday_day_range: number;
    onthisday_photos_per_year: number;

    // folder settings
    folders_path: string;
    show_hidden_folders: boolean;
    sort_folder_month: boolean;

    // album settings
    sort_album_month: boolean;
    show_hidden_albums: boolean;

    // local settings
    square_thumbs: boolean;
    high_res_cond: HighResCond | null;
    show_face_rect: boolean;
    album_list_sort: number;
  };

  export type IMapTileServer = {
    name: string;
    url: string;
    attribution: string;
    maxZoom: number;
    csp: string[];
  };
}
