/** Bundles PhotoSphere deps into a single lazy-loaded chunk. */
import '@photo-sphere-viewer/core/index.css';
import '@photo-sphere-viewer/video-plugin/index.css';

export { Viewer } from '@photo-sphere-viewer/core';
export { VisibleRangePlugin } from '@photo-sphere-viewer/visible-range-plugin';
export { EquirectangularVideoAdapter } from '@photo-sphere-viewer/equirectangular-video-adapter';
export { VideoPlugin } from '@photo-sphere-viewer/video-plugin';
