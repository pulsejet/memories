import { getPlayableVideoCodecsSync } from '@services/video/codec';

import { API } from '@services/API';

import type { IPhoto } from '@typings';

/**
 * Get URL to Live Photo video part
 */
export function getLivePhotoVideoUrl(p: IPhoto, transcode: boolean) {
  return API.Q(API.VIDEO_LIVEPHOTO(p.fileid), {
    etag: p.etag,
    liveid: p.liveid,
    transcode: transcode ? _m.video.clientIdPersistent : undefined,
    codecs: transcode ? getPlayableVideoCodecsSync()?.join(',') : undefined,
  });
}

/**
 * Set up hooks to set classes on parent element for Live Photo
 * @param video Video element
 * @param state State object to update (reactivity)
 */
export function setupLivePhotoHooks(video: HTMLVideoElement, state: { playing: boolean }) {
  const div = video.closest('.memories-livephoto') as HTMLDivElement;

  // Playing state
  video.addEventListener('playing', () => (state.playing = true));
  video.addEventListener('play', () => div.classList.add('playing'));
  video.addEventListener('canplay', () => div.classList.add('canplay'));

  // Ended or pausing state
  const ended = () => {
    state.playing = false;
    div.classList.remove('playing');
  };
  video.addEventListener('ended', ended);
  video.addEventListener('pause', ended);
}
