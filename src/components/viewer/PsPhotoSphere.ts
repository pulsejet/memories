import { API } from '@services/API';
import { translate as t } from '@services/l10n';

import type PhotoSwipe from 'photoswipe';
import type { Viewer as PhotoSphereViewer } from '@photo-sphere-viewer/core';
import type { PsContent } from './types';

export default class PhotoSphereContentSetup {
  private viewer: PhotoSphereViewer | null = null;
  private container: HTMLElement | null = null;
  private content: PsContent | null = null;

  /** Photos the user explicitly closed the sphere for */
  private dismissed = new Set<number>();

  /** Tap start for manual control toggling */
  private tapX = 0;
  private tapY = 0;
  private tapT = 0;

  constructor(private lightbox: PhotoSwipe) {
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
    if (!content || content.data?.type === 'video') return;
    if ((content.data?.photo?.pano ?? 0) <= 0) return;

    this.dismissed.delete(content.data.photo.fileid);
    await this.show(content);
  }

  private onContentActivate(content: PsContent): void {
    if (this.content && this.content !== content) this.close();
    if (content.data?.type === 'video') return;
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

    try {
      const [{ Viewer }, { VisibleRangePlugin }] = await Promise.all([
        import('@photo-sphere-viewer/core'),
        import('@photo-sphere-viewer/visible-range-plugin'),
        import('@photo-sphere-viewer/core/index.css'),
      ]);

      if (this.viewer) return;
      if (this.dismissed.has(photo.fileid)) return;
      if (content.slide !== this.lightbox.currSlide || !holder.isConnected) return;
      this.close();

      // Create the photosphere container.
      const container = document.createElement('div');
      container.className = 'memories-photosphere';

      // Hide sphere gestures from PhotoSwipe so drags can't navigate;
      // taps are reimplemented in onTapEnd for control toggling.
      container.addEventListener('pointerdown', this.onTapStart.bind(this));
      container.addEventListener('pointerup', this.onTapEnd.bind(this));
      container.addEventListener('pointercancel', this.onTapCancel.bind(this));
      container.addEventListener('wheel', (e) => e.stopPropagation(), { passive: true });

      // Add the container to the slide holder.
      holder.appendChild(container);

      this.viewer = new Viewer({
        container,
        panorama: API.IMAGE_DECODABLE(photo.fileid, photo.etag),
        loadingTxt: t('memories', 'Loading …'),
        navbar: false,
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
      });
      this.container = container;
      this.content = content;
    } catch (e) {
      console.error('PsPhotoSphere: failed to show panorama', e);
    }
  }

  public close(): void {
    try {
      this.viewer?.destroy();
    } catch {
      // Already destroyed.
    }
    this.container?.remove();
    this.viewer = null;
    this.container = null;
    this.content = null;
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
