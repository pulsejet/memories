import { API } from '@services/API';
import { translate as t } from '@services/l10n';
import { config } from '@services/user-config';
import { getWebPlayer } from './PsVideo';

import type { Viewer as PhotoSphereViewer, ViewerConfig } from '@photo-sphere-viewer/core';
import type PsVideo from './PsVideo';
import type { PsContent, PsPhotoSwipe } from './types';

export default class PhotoSphereContentSetup {
  private viewer: PhotoSphereViewer | null = null;
  private container: HTMLElement | null = null;
  private content: PsContent | null = null;
  private video: HTMLVideoElement | null = null;

  /** Photos the user explicitly closed the sphere for */
  private dismissed = new Set<number>();

  /** Tap start for manual control toggling */
  private tapX = 0;
  private tapY = 0;
  private tapT = 0;

  constructor(
    private lightbox: PsPhotoSwipe,
    psVideo: PsVideo,
  ) {
    // Videos open once their player is mounted, since the sphere draws from it.
    psVideo.onWebPlayerMount = (content) => this.autoOpen(content);

    lightbox.on('contentActivate', (e) => this.onContentActivate((e as unknown as { content: PsContent }).content));
    lightbox.on('contentDeactivate', (e) => this.onContentEnd((e as unknown as { content: PsContent }).content));
    lightbox.on('contentDestroy', (e) => this.onContentEnd((e as unknown as { content: PsContent }).content));
    lightbox.on('destroy', () => this.close());
  }

  public async toggle(): Promise<void> {
    if (this.viewer) {
      const fileid = this.content?.data?.photo?.fileid;
      this.close();
      if (fileid !== undefined) this.dismissed.add(fileid);
      return;
    }

    const content = this.lightbox.currSlide?.content as unknown as PsContent | undefined;
    if (!content) return;
    if ((content.data?.photo?.pano ?? 0) <= 0) return;

    this.dismissed.delete(content.data.photo.fileid);
    await this.show(content);
  }

  private onContentActivate(content: PsContent): void {
    if (this.content && this.content !== content) this.close();
    if (content.data?.type !== 'video') this.autoOpen(content);
  }

  private autoOpen(content: PsContent): void {
    const photo = content.data?.photo;
    if (!photo || photo.pano !== 2) return;
    if (this.dismissed.has(photo.fileid)) return;

    void this.show(content);
  }

  private onContentEnd(content: PsContent): void {
    if (content === this.content) this.close();
  }

  private async show(content: PsContent): Promise<void> {
    if (this.viewer) return;

    const holder = content.slide?.holderElement;
    const photo = content.data?.photo;
    if (!holder || !photo) return;

    // A video is drawn from its web player, mounted with the controls above the sphere.
    const isVideo = content.data.type === 'video';
    const video = isVideo ? getWebPlayer(content) : null;
    const parent = isVideo ? content.element : holder;
    if (isVideo && !video) return;

    // Hide the flat player before it is first painted. Firefox on Android gives a
    // video's frames to a single consumer (Mozilla bug 1842735), and a painted
    // player starves the sphere.
    const flat = isVideo ? content.element : null;
    flat?.classList.add('memories-video--sphere');
    let opened = false;

    try {
      const { Viewer, VisibleRangePlugin, EquirectangularVideoAdapter, VideoPlugin } =
        await import('./PsPhotoSphereLib');

      if (this.viewer) return;
      if (this.dismissed.has(photo.fileid)) return;
      if (content.slide !== this.lightbox.currSlide || !holder.isConnected) return;
      if (!parent?.isConnected || (isVideo && getWebPlayer(content) !== video)) return;
      this.close();

      // Create the photosphere container.
      const container = document.createElement('div');
      container.className = video ? 'memories-photosphere memories-photosphere--video' : 'memories-photosphere';

      // Hide sphere gestures from PhotoSwipe so drags can't navigate;
      // taps are reimplemented in onTapEnd for control toggling.
      container.addEventListener('pointerdown', this.onTapStart.bind(this));
      container.addEventListener('pointerup', this.onTapEnd.bind(this));
      container.addEventListener('pointercancel', this.onTapCancel.bind(this));
      container.addEventListener('wheel', (e) => e.stopPropagation(), { passive: true });

      // Add the container to the slide.
      parent.appendChild(container);

      const source: Partial<ViewerConfig> = video
        ? {
            adapter: [EquirectangularVideoAdapter, { muted: video.muted }],
            panorama: { source: video },
            // Memories' own controls drive the player.
            plugins: [[VideoPlugin, { progressbar: false, bigbutton: false }]],
          }
        : {
            panorama: API.IMAGE_DECODABLE(photo.fileid, photo.etag),
            // A partial panorama covers only part of the sphere, and the default
            // view may be outside it; keep the view on the image.
            plugins: [[VisibleRangePlugin, { usePanoData: true }]],
            // The plugin's left/right range ignores the GPano compass heading,
            // which turns the sphere, so a crop narrower than 360° with a heading
            // would be limited to the wrong part of it. Drop the heading there;
            // without a compass it only decides which way the view starts.
            panoData: (_image, xmpData) => {
              const narrow = !!xmpData?.croppedWidth && xmpData.croppedWidth < xmpData.fullWidth;
              return narrow ? { ...xmpData, poseHeading: 0 } : xmpData!;
            },
          };

      this.viewer = new Viewer({
        container,
        loadingTxt: t('memories', 'Loading …'),
        navbar: false,
        ...source,
      });
      this.container = container;
      this.content = content;
      this.video = video;
      opened = true;

      if (video) {
        // The adapter waits for loadedmetadata, which a loaded player has
        // already fired; it starts listening in the constructor, so repeat it.
        if (video.readyState >= HTMLMediaElement.HAVE_METADATA) {
          video.dispatchEvent(new Event('loadedmetadata'));
        }

        // The adapter sets full volume when it takes the player; restore the user's.
        // The texture updates only when a new frame is presented, which a paused
        // player never does; seeking in place presents the current one.
        this.viewer.addEventListener(
          'panorama-loaded',
          () => {
            video.volume = config.video_volume;
            video.muted = config.video_muted;
            if (video.paused) video.currentTime = video.currentTime;
          },
          { once: true },
        );

        // PsVideo handles Space; the plugin sees every key and would toggle it back.
        const plugin = this.viewer.getPlugin(VideoPlugin) as unknown as EventListenerObject;
        this.viewer.removeEventListener('key-press', plugin);
      }

      // Disable the native image when sphere is active.
      if (content.slide) {
        content.slide.data.nxImageDisabled ??= 0;
        content.slide.data.nxImageDisabled++;
        this.lightbox.dispatch('initialZoomPan');
      }
    } catch (e) {
      console.error('PsPhotoSphere: failed to show panorama', e);
    } finally {
      if (!opened && this.content !== content) flat?.classList.remove('memories-video--sphere');
    }
  }

  public close(): void {
    // The adapter removes the player it was given; put it back for the flat view.
    const video = this.video;
    const videoParent = video?.parentNode;
    const videoNext = video?.nextSibling ?? null;
    const playing = !!video && !video.paused;

    try {
      this.viewer?.destroy();
    } catch {
      // Already destroyed.
    }
    this.container?.remove();
    this.viewer = null;
    this.container = null;
    this.video = null;

    if (video) {
      if (videoParent && video.parentNode !== videoParent) videoParent.insertBefore(video, videoNext);
      this.content?.element?.classList.remove('memories-video--sphere');

      // A paused player shows nothing until it presents a frame (Firefox on Android).
      if (playing) {
        video.play().catch(() => {});
      } else if (video.readyState >= HTMLMediaElement.HAVE_METADATA) {
        video.currentTime = video.currentTime;
      }
    }

    // Re-enable the native image when sphere is closed.
    if (this.content) {
      if (this.content.slide) {
        this.content.slide.data.nxImageDisabled ??= 1;
        this.content.slide.data.nxImageDisabled--;
        this.lightbox.dispatch('initialZoomPan');
      }
      this.content = null;
    }
  }

  private onTapStart(e: PointerEvent): void {
    e.stopPropagation();
    this.tapX = e.clientX;
    this.tapY = e.clientY;
    this.tapT = Date.now();
  }

  private onTapEnd(e: PointerEvent): void {
    const moved = Math.hypot(e.clientX - this.tapX, e.clientY - this.tapY);
    if (moved > 10 || Date.now() - this.tapT > 300) return;
    this.lightbox.element?.classList.toggle('pswp--ui-visible');
  }

  private onTapCancel(): void {
    this.tapT = 0;
  }
}
