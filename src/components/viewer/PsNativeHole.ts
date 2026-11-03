import * as nativex from '@native';
import { windowDims } from '@services/viewport';
import { RenewingTimeout } from '@services/utils/renewing-timeout';

import type { PsPhotoSwipe, PsSlide } from './types';

export default class PsNativeHole {
  private clipRaf = 0;
  private hideRaf = 0;
  private opened = false;
  private revealTimer = new RenewingTimeout();
  private lastClip: string | null = null;
  private lastNative: string | null = null;
  private lastType: 'image' | 'video' | null = null;
  private bgElement: HTMLElement | null = null;
  private contentElement: HTMLElement | null = null;
  private holderElement: HTMLElement | null = null;

  constructor(private lightbox: PsPhotoSwipe) {
    if (!nativex.viewImageIsEnabled() && !nativex.viewVideoIsEnabled()) return;

    const updateEvents = [
      'zoomPanUpdate',
      'firstZoomPan',
      'change',
      'slideActivate',
      'moveMainScroll',
      'contentResize',
      'resize',
      'viewportSize',
      'updateScrollOffset',
      'afterInit',
      'openingAnimationEnd',
    ] as const;
    for (const e of updateEvents) {
      lightbox.on(e, () => this.scheduleClipUpdate());
    }

    // We (ab-)use this event to indicate a force update should happen.
    lightbox.on('initialZoomPan', (e) => {
      this.lastNative = null;
      this.scheduleClipUpdate();
    });

    const resetEvents = ['close', 'closingAnimationStart', 'destroy'] as const;
    for (const e of resetEvents) {
      lightbox.on(e, () => this.reset());
    }

    // Get the elements to put a hole in after fully opened.
    // Do not use tricks like CSS vars to prevent style invalidation.
    // Revealing mid-animation hides the zooming img and flips HDR (surface reconfigure).
    lightbox.on('openingAnimationEnd', () => {
      this.opened = true;
      this.bgElement = this.lightbox.bg ?? null;
      this.contentElement = document.querySelector<HTMLElement>('body > #content');
      if (this.lastClip === 'full') {
        this.setFullClip(true);
      } else {
        this.setClip(this.lastClip ?? 'none');
      }
    });

    // Toggling HDR reconfigures the window surface; slow.
    lightbox.on('destroy', () => {
      nativex.nxAsync('setHdrColorMode')?.(false);
    });
  }

  private setClip(clip: string) {
    if (this.bgElement) this.bgElement.style.clipPath = clip;
    if (this.contentElement) this.contentElement.style.clipPath = clip;
  }

  private setFullClip(full: boolean) {
    if (this.bgElement) this.bgElement.style.background = full ? 'transparent' : '';
    if (this.contentElement) this.contentElement.style.opacity = full ? '0' : '';
  }

  private reset() {
    this.opened = false;
    if (this.clipRaf) {
      cancelAnimationFrame(this.clipRaf);
      this.clipRaf = 0;
    }
    this.hide();
  }

  private hide() {
    this.holderElement?.classList.remove('nativex-hole');
    this.holderElement?.classList.remove('nativex-fading');
    this.holderElement = null;
    this.revealTimer.clear();

    if (this.lastClip === 'none' && !this.lastType) return;
    this.lastClip = 'none';
    this.lastNative = null;
    this.lastType = null;
    this.setClip('none');
    this.setFullClip(false);

    if (!this.hideRaf) {
      this.hideRaf = requestAnimationFrame(() => {
        this.hideRaf = 0;
        this.clearNative();
      });
    }
  }

  private clearNative() {
    nativex.nxAsync('setNativeImageRect')?.(0, 0, 0, 0);
    nativex.nxAsync('showNativeImage')?.(false);
    nativex.nxAsync('setNativeVideoRect')?.(0, 0, 0, 0);
    nativex.nxAsync('showNativeVideo')?.(false);
  }

  private scheduleClipUpdate() {
    if (this.clipRaf) return;
    this.clipRaf = requestAnimationFrame(() => {
      this.clipRaf = 0;
      this.updateClip();
    });
  }

  private updateClip() {
    if (!this.opened) return;
    const slide = this.lightbox.currSlide as unknown as PsSlide | undefined;
    const slideType = slide?.data.type;

    // Pinch/drag to close fades the bg to show the timeline.
    if (this.lightbox.bgOpacity < 1) {
      this.hide(); // resets nativex-fading
      slide?.holderElement?.classList.add('nativex-fading');
      return;
    } else {
      slide?.holderElement?.classList.remove('nativex-fading');
    }

    if (!slide || !slideType) {
      return this.hide();
    } else if (slideType === 'image') {
      if (!nativex.viewImageIsEnabled() || slide.data.nxImageState !== 'loaded' || slide.data.nxImageDisabled) {
        return this.hide();
      }
    } else if (slideType === 'video') {
      if (!nativex.viewVideoIsEnabled() || slide.data.nxVideoState !== 'loaded') {
        return this.hide();
      }
    }

    const holderElement = slide.holderElement;

    let element: HTMLElement | null | undefined = slide.content?.element;
    if (slideType === 'video') {
      element = element?.querySelector?.<HTMLElement>('viewer-video-native-hole');
    }

    const box = element?.getBoundingClientRect();
    if (!holderElement || !element || !box || !box.width || !box.height) {
      return this.hide();
    }

    const W = windowDims.width;
    const H = windowDims.height;
    if (!W || !H) return this.hide();

    const x1 = PsNativeHole.snapPx(box.left);
    const y1 = PsNativeHole.snapPx(box.top);
    const x2 = PsNativeHole.snapPx(box.right);
    const y2 = PsNativeHole.snapPx(box.bottom);
    if (x2 <= x1 || y2 <= y1) return this.hide();

    const cx1 = Math.max(0, Math.min(W, x1));
    const cy1 = Math.max(0, Math.min(H, y1));
    const cx2 = Math.max(0, Math.min(W, x2));
    const cy2 = Math.max(0, Math.min(H, y2));

    if (cx2 <= cx1 || cy2 <= cy1) return this.hide();

    if (this.lastClip !== 'full') {
      this.lastClip = 'full';
      this.setFullClip(true);
    }

    if (this.holderElement !== holderElement) {
      this.holderElement?.classList.remove('nativex-hole');
      this.holderElement?.classList.remove('nativex-fading');
      this.holderElement = holderElement;

      // Unfortunately there's no way to know when the native image has actually
      // finished tiled rendering, so we just wait a bit before revealing it.
      this.revealTimer.set(() => {
        this.holderElement?.classList.add('nativex-hole');
        nativex.nxAsync('setHdrColorMode')?.(true);
      }, 50);
    }

    if (this.lastType !== slideType) {
      this.clearNative();
      this.lastType = slideType;
    }

    const key = `${slideType}:${x1},${y1},${x2 - x1},${y2 - y1}`;
    if (key === this.lastNative) return;
    this.lastNative = key;
    if (slideType === 'video') {
      nativex.nxAsync('setNativeVideoRect')?.(x1, y1, x2 - x1, y2 - y1);
      nativex.nxAsync('showNativeVideo')?.(true);
    } else {
      nativex.nxAsync('setNativeImageRect')?.(x1, y1, x2 - x1, y2 - y1);
      nativex.nxAsync('showNativeImage')?.(true);
    }
    if (this.hideRaf) {
      cancelAnimationFrame(this.hideRaf);
      this.hideRaf = 0;
    }
  }

  /**
   * Snap a CSS px value to the device pixel grid.
   * Integer CSS px still lands between physical pixels on
   * fractional DPR, producing an AA fringe in the hole.
   */
  private static snapPx(v: number) {
    const dpr = window.devicePixelRatio || 1;
    const devicePx = Math.round(v * dpr);
    return Math.round(devicePx * (1000 / dpr)) / 1000;
  }

  /** Viewport minus the hole, for {@link setClip}. */
  private static _holeClipPath(W: number, H: number, x1: number, y1: number, x2: number, y2: number) {
    return `path(evenodd, 'M-1 -1 H${W + 1} V${H + 1} H-1 Z M${x1} ${y1} H${x2} V${y2} H${x1} Z')`;
  }
}
