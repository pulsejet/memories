import PhotoSwipe from 'photoswipe';
import PsImage from './PsImage';

import * as utils from '@services/utils';
import staticConfig from '@services/static-config';

import type { PsContent, PsEvent } from './types';

export function isLiveContent(content: PsContent): boolean {
  // Do not play Live Photo if the slideshow is
  // playing in full screen mode.
  if (document.fullscreenElement) {
    return false;
  }

  return Boolean(content?.data?.photo?.liveid);
}

class LivePhotoContentSetup {
  constructor(
    lightbox: PhotoSwipe,
    private psImage: PsImage,
    private liveState: { playing: boolean; waiting: boolean },
  ) {
    lightbox.on('contentLoad', this.onContentLoad.bind(this));
    lightbox.on('contentActivate', this.onContentActivate.bind(this));
    lightbox.on('contentDeactivate', this.onContentDeactivate.bind(this));
    lightbox.on('contentDestroy', this.onContentDestroy.bind(this));
  }

  async play(content: PsContent) {
    const video = content.element?.querySelector('video');
    if (!video) return;

    if (!video.paused) {
      video.pause();
      return;
    }

    try {
      this.liveState.waiting = true;
      video.currentTime = 0;
      await video.play();
    } catch (e) {
      // ignore, pause was probably called too soon
    } finally {
      this.liveState.waiting = false;
    }
  }

  onContentLoad(e: PsEvent) {
    const content: PsContent = e.content;
    if (!isLiveContent(content)) return;

    e.preventDefault();
    if (content.element) return;

    const photo = content?.data?.photo;

    const video = document.createElement('video');
    video.preload = 'none';
    video.playsInline = true;
    video.disableRemotePlayback = true;
    video.autoplay = false;
    video.loop = true;
    video.src = utils.getLivePhotoVideoUrl(photo, true);

    const div = document.createElement('div');
    div.className = 'memories-livephoto';
    div.appendChild(video);
    content.element = div;

    utils.setupLivePhotoHooks(video, this.liveState);

    const img = this.psImage.getXImgElem(content, () => content.onLoaded());
    div.appendChild(img);

    content.element = div;
  }

  onContentActivate({ content }: { content: PsContent }) {
    if (!isLiveContent(content)) return;

    if (staticConfig.getSync('livephoto_autoplay')) {
      this.play(content);
    }
  }

  onContentDeactivate({ content }: PsEvent) {
    if (isLiveContent(content)) {
      content.element?.querySelector('video')?.pause();
    }
  }

  onContentDestroy({ content }: PsEvent) {
    if (isLiveContent(content)) {
      content.element?.remove();
    }
  }
}

export default LivePhotoContentSetup;
