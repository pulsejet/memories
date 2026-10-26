import * as nativex from '@native';
import { RenewingTimeout } from '@services/utils/renewing-timeout';

import type PhotoSwipe from 'photoswipe';
import type { PsSlide } from './types';

export default class PsNativeHole {
  private clipRaf = 0;
  private hideRaf = 0;
  private revealTimer = new RenewingTimeout();
  private lastClip: string | null = null;
  private lastNative: string | null = null;
  private holeElements: HTMLElement[] = [];
  private holderElement: HTMLElement | null = null;

  constructor(private lightbox: PhotoSwipe) {
    if (!nativex.viewImageIsEnabled()) return;

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
    lightbox.on('openingAnimationEnd', () => {
      this.holeElements = Array.from(document.querySelectorAll('.pswp__bg, body > #content'));
      this.setClip(this.lastClip ?? 'none');
    });
  }

  private setClip(clip: string) {
    for (const el of this.holeElements) {
      el.style.clipPath = clip;
    }
  }

  private reset() {
    if (this.clipRaf) {
      cancelAnimationFrame(this.clipRaf);
      this.clipRaf = 0;
    }
    this.hide();
  }

  private hide() {
    this.holderElement?.classList.remove('nativex-hole');
    this.holderElement = null;
    this.revealTimer.clear();

    if (this.lastClip === 'none') return;
    this.lastClip = 'none';
    this.lastNative = null;
    this.setClip('none');

    if (!this.hideRaf) {
      this.hideRaf = requestAnimationFrame(() => {
        this.hideRaf = 0;
        nativex.nxAsync('setHdrColorMode')?.(false);
        nativex.nxAsync('setNativeImageRect')?.(0, 0, 0, 0);
        nativex.nxAsync('showNativeImage')?.(false);
      });
    }
  }

  private scheduleClipUpdate() {
    if (this.clipRaf) return;
    this.clipRaf = requestAnimationFrame(() => {
      this.clipRaf = 0;
      this.updateClip();
    });
  }

  private updateClip() {
    const slide = this.lightbox.currSlide as unknown as PsSlide | undefined;
    if (slide?.data.nxImageState !== 'loaded' || slide?.data.nxImageDisabled) {
      return this.hide();
    }

    const element = slide.content?.element;
    const holderElement = slide.holderElement;
    const box = element?.getBoundingClientRect();
    if (!holderElement || !element || !box || !box.width || !box.height) {
      return this.hide();
    }

    const W = window.innerWidth;
    const H = window.innerHeight;
    if (!W || !H) return this.hide();

    const x1 = Math.round(box.left);
    const y1 = Math.round(box.top);
    const x2 = Math.round(box.right);
    const y2 = Math.round(box.bottom);

    const cx1 = Math.max(0, Math.min(W, x1));
    const cy1 = Math.max(0, Math.min(H, y1));
    const cx2 = Math.max(0, Math.min(W, x2));
    const cy2 = Math.max(0, Math.min(H, y2));

    if (cx2 <= cx1 || cy2 <= cy1) return this.hide();

    const clip = `path(evenodd, 'M0 0 H${W} V${H} H0 Z M${cx1} ${cy1} H${cx2} V${cy2} H${cx1} Z')`;
    if (clip !== this.lastClip) {
      this.lastClip = clip;
      this.setClip(clip);
    }

    if (this.holderElement !== holderElement) {
      this.holderElement?.classList.remove('nativex-hole');
      this.holderElement = holderElement;

      // Unfortunately there's no way to know when the native image has actually
      // finished tiled rendering, so we just wait a bit before revealing it.
      this.revealTimer.set(() => {
        this.holderElement?.classList.add('nativex-hole');
        nativex.nxAsync('setHdrColorMode')?.(true);
      }, 50);
    }

    const key = `${x1},${y1},${x2 - x1},${y2 - y1}`;
    if (key === this.lastNative) return;
    this.lastNative = key;
    nativex.nxAsync('setNativeImageRect')?.(x1, y1, x2 - x1, y2 - y1);
    nativex.nxAsync('showNativeImage')?.(true);
    if (this.hideRaf) {
      cancelAnimationFrame(this.hideRaf);
      this.hideRaf = 0;
    }
  }
}
