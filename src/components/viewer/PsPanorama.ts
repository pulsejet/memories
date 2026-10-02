import type PhotoSwipe from 'photoswipe';

import type { IExif, IPhoto } from '@typings';
import type { PsContent } from './types';

import * as utils from '@services/utils';

/**
 * Texture width to ask the server for, in pixels.
 *
 * Matches the ceiling of Nextcloud's own preview_max_x default. Asking for
 * more gets silently clamped, so there is nothing to gain past this.
 *
 * It is a request, not a promise: the server's preview size limits, or a
 * preview provider, may return something smaller.
 */
const PANORAMA_TEXTURE = 4096;

/**
 * Geometry of a spherical photo, from its GPano XMP metadata.
 *
 * https://developers.google.com/streetview/spherical-metadata
 */
export type PanoramaInfo = {
  /** Width of the stored image, in pixels. */
  croppedWidth: number;
  /** Height of the stored image, in pixels. */
  croppedHeight: number;
  /** Width of the complete sphere this is a crop of. */
  fullWidth: number;
  /** Height of the complete sphere this is a crop of. */
  fullHeight: number;
  /** Where the crop starts within that sphere. */
  left: number;
  top: number;
  /** Whether the image covers the entire sphere. */
  isFull: boolean;
};

/**
 * Whether a file's own bytes are the image being displayed.
 *
 * They are not always, and the failure is invisible until someone zooms. A
 * file can carry GPano describing an image other than the one it stores: a 360
 * camera container, for instance, whose JPEG is a pair of fisheye circles and
 * whose panorama exists only as the preview a preview provider renders.
 * Swapping in "the original, for more detail" then replaces the panorama with
 * two circles.
 *
 * GPano's `CroppedAreaImage*` fields are a statement about the STORED pixels,
 * so comparing them against the file's real dimensions settles it without
 * knowing anything about any vendor or container.
 *
 * Three-valued on purpose. Metadata arrives asynchronously, so 'unknown'
 * means "ask again later" -- answering 'yes' there would race and answering
 * 'no' would cost every ordinary photo its full-resolution view.
 *
 * Conservative in the remaining case: a genuinely equirectangular image that
 * was downscaled after its metadata was written also answers 'no' and loses a
 * swap it could have had. That costs detail. The other direction costs
 * correctness.
 */
export function originalIsTheSameImage(photo: IPhoto | null | undefined): 'yes' | 'no' | 'unknown' {
  const exif: IExif | undefined = photo?.imageInfo?.exif;
  if (exif === undefined) return 'unknown';

  // No panorama claim: nothing here contradicts the bytes.
  if (exif.ProjectionType !== 'equirectangular') return 'yes';

  const width = exif.CroppedAreaImageWidthPixels;
  const height = exif.CroppedAreaImageHeightPixels;

  // Absent is legal and means the image is the whole sphere, i.e. itself.
  if (!width || !height) return 'yes';
  if (!photo?.w || !photo?.h) return 'unknown';

  return width === photo.w && height === photo.h ? 'yes' : 'no';
}

/**
 * Read the panorama geometry out of a photo's EXIF, or null if it is not one.
 *
 * Detection is on `ProjectionType`, never on the aspect ratio. A 2:1 image
 * is not evidence of anything: a side-by-side fisheye pair from a 360 camera
 * is also 2:1, and showing one of those as a sphere is worse than leaving it
 * flat. Ordinary wide crops are 2:1 often enough to matter too.
 *
 * This depends on `imageInfo` having been loaded for the photo, which the
 * viewer does asynchronously. Callers must tolerate a null answer that later
 * becomes non-null.
 */
export function panoramaInfo(photo: IPhoto | null | undefined): PanoramaInfo | null {
  const exif: IExif | undefined = photo?.imageInfo?.exif;
  if (exif?.ProjectionType !== 'equirectangular') return null;

  // The cropped-area fields are optional in the spec. When they are absent
  // the image is the whole sphere, which is also the overwhelmingly common
  // case, so fall back to the stored dimensions rather than refusing.
  const croppedWidth = exif.CroppedAreaImageWidthPixels ?? photo?.w ?? 0;
  const croppedHeight = exif.CroppedAreaImageHeightPixels ?? photo?.h ?? 0;
  if (croppedWidth <= 0 || croppedHeight <= 0) return null;

  const fullWidth = exif.FullPanoWidthPixels ?? croppedWidth;
  const fullHeight = exif.FullPanoHeightPixels ?? croppedHeight;
  const left = exif.CroppedAreaLeftPixels ?? 0;
  const top = exif.CroppedAreaTopPixels ?? 0;

  // A crop that does not fit inside the sphere it claims to come from is not
  // something to guess about.
  if (fullWidth < croppedWidth + left || fullHeight < croppedHeight + top) return null;

  return {
    croppedWidth,
    croppedHeight,
    fullWidth,
    fullHeight,
    left,
    top,
    isFull: croppedWidth === fullWidth && croppedHeight === fullHeight,
  };
}

/**
 * Clamp to [lo + pad, hi - pad], or to the middle when the padding leaves
 * nothing -- which happens when the covered band is narrower than the field
 * of view, and pinning to the centre is the best available answer.
 */
function clampWithin(value: number, lo: number, hi: number, pad: number): number {
  const min = lo + pad;
  const max = hi - pad;
  if (min > max) return (lo + hi) / 2;

  return Math.max(min, Math.min(max, value));
}

/**
 * A spherical view of one image, rendered with three.js.
 *
 * three.js is loaded with a dynamic import so it lands in its own chunk and
 * costs nothing to anyone who never opens a panorama.
 */
export class PanoramaView {
  private canvas: HTMLCanvasElement;
  private disposers: (() => void)[] = [];
  private destroyed = false;

  /** Set once mounted, so a sharper texture can replace the first one. */
  private swapTexture: ((src: string) => Promise<void>) | null = null;

  /** Current view direction, degrees. */
  private longitude = 0;
  private latitude = 0;
  private fov = 75;

  /** Angular bounds, in degrees, of the sphere that actually carries texture. */
  private limits = {
    latMin: -85,
    latMax: 85,
    lonMin: -Infinity,
    lonMax: Infinity,
    fovMax: 100,
    /** Whether the vertical extent is a crop edge rather than the pole. */
    latCropped: false,
  };

  private constructor(
    public readonly element: HTMLElement,
    canvas: HTMLCanvasElement,
  ) {
    this.canvas = canvas;
  }

  public static async create(src: string, info: PanoramaInfo): Promise<PanoramaView> {
    const THREE = await import('three');

    const container = document.createElement('div');
    container.className = 'memories-panorama';

    const canvas = document.createElement('canvas');
    container.appendChild(canvas);

    const view = new PanoramaView(container, canvas);
    await view.mount(THREE, src, info);

    return view;
  }

  private async mount(THREE: typeof import('three'), src: string, info: PanoramaInfo) {
    const texture = await new THREE.TextureLoader().loadAsync(src);
    if (this.destroyed) {
      texture.dispose();
      return;
    }
    texture.colorSpace = THREE.SRGBColorSpace;

    const renderer = new THREE.WebGLRenderer({ canvas: this.canvas, antialias: true });
    renderer.setPixelRatio(window.devicePixelRatio);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(this.fov, 1, 0.1, 1000);

    // Map the stored crop onto the part of the sphere it actually covers.
    // Without this a partial panorama gets stretched around the whole sphere,
    // which looks plausible rather than broken -- so it is worth doing even
    // though most panoramas are complete.
    const phiStart = (info.left / info.fullWidth) * Math.PI * 2;
    const phiLength = (info.croppedWidth / info.fullWidth) * Math.PI * 2;
    const thetaStart = (info.top / info.fullHeight) * Math.PI;
    const thetaLength = (info.croppedHeight / info.fullHeight) * Math.PI;

    this.setLimits(info);

    // MIRROR. A sphere is wound so its faces point outward, and simply
    // rendering the back faces from inside shows the texture through the
    // surface -- i.e. flipped left to right. Every panorama came out mirrored,
    // which is invisible on scenery and obvious the moment a sign is in shot.
    //
    // Scaling x by -1 inverts the sphere instead, so the inside is the front
    // and the texture reads the right way round. This is what three's own
    // equirectangular example does. The half-turn on phiStart puts longitude 0
    // back at the middle column of the image, which the inversion moves.
    const geometry = new THREE.SphereGeometry(100, 64, 48, phiStart - Math.PI / 2, phiLength, thetaStart, thetaLength);
    geometry.scale(-1, 1, 1);
    const material = new THREE.MeshBasicMaterial({ map: texture });
    const mesh = new THREE.Mesh(geometry, material);
    scene.add(mesh);

    let needsRender = true;

    // Progressive detail. The texture this opened with is the screen-sized
    // preview, which is ample for a flat photo and thin spread over a whole
    // sphere -- 360 degrees across 1920 pixels is 5 per degree. A sharper one
    // is fetched after the sphere is already up, because generating it can
    // take seconds and an empty viewer for that long is worse than a soft one.
    this.swapTexture = async (next: string) => {
      const loaded = await new THREE.TextureLoader().loadAsync(next);
      if (this.destroyed) {
        loaded.dispose();
        return;
      }
      loaded.colorSpace = THREE.SRGBColorSpace;
      const previous = material.map;
      material.map = loaded;
      material.needsUpdate = true;
      previous?.dispose();
      needsRender = true;
    };

    const resize = () => {
      const width = this.element.clientWidth || 1;
      const height = this.element.clientHeight || 1;
      renderer.setSize(width, height, false);
      camera.aspect = width / height;
      camera.updateProjectionMatrix();
      needsRender = true;
    };

    const observer = new ResizeObserver(resize);
    observer.observe(this.element);
    resize();

    let frame = 0;
    const tick = () => {
      if (this.destroyed) return;
      frame = requestAnimationFrame(tick);
      if (!needsRender) return;
      needsRender = false;

      camera.fov = this.fov;
      camera.updateProjectionMatrix();

      // Direction for (longitude, latitude) in the same convention the
      // geometry above now uses: x negated, and longitude measured from the
      // middle column of the image.
      const phi = THREE.MathUtils.degToRad(90 - this.latitude);
      const theta = THREE.MathUtils.degToRad(this.longitude);
      camera.lookAt(-100 * Math.sin(phi) * Math.sin(theta), 100 * Math.cos(phi), 100 * Math.sin(phi) * Math.cos(theta));
      renderer.render(scene, camera);
    };
    tick();

    const invalidate = () => {
      needsRender = true;
    };
    this.attachControls(invalidate);

    this.disposers.push(() => {
      cancelAnimationFrame(frame);
      observer.disconnect();
      geometry.dispose();
      material.dispose();
      texture.dispose();
      renderer.dispose();
    });
  }

  /**
   * Work out how much of the sphere is painted, and start in the middle of it.
   *
   * A cropped panorama covers only part of the sphere; the rest renders black.
   * Without bounds the user can drag straight off the image into that void,
   * which reads as the picture having failed to load -- which is exactly how
   * it was first reported.
   */
  private setLimits(info: PanoramaInfo) {
    const thetaStart = (info.top / info.fullHeight) * 180;
    const thetaEnd = thetaStart + (info.croppedHeight / info.fullHeight) * 180;
    const phiStart = (info.left / info.fullWidth) * 360;
    const phiEnd = phiStart + (info.croppedWidth / info.fullWidth) * 360;

    // Latitude is 90 minus the polar angle, and longitude runs against the
    // sphere's phi, so both bounds swap ends on the way across.
    const latMin = 90 - thetaEnd;
    const latMax = 90 - thetaStart;
    const wraps = info.croppedWidth >= info.fullWidth;
    const latCropped = info.croppedHeight < info.fullHeight;

    this.limits = {
      latMin: Math.max(-85, latMin),
      latMax: Math.min(85, latMax),
      lonMin: wraps ? -Infinity : 180 - phiEnd,
      lonMax: wraps ? Infinity : 180 - phiStart,
      // Zooming out far enough would reveal the edge just as surely as
      // looking away from it.
      fovMax: latCropped ? Math.min(100, Math.max(20, latMax - latMin)) : 100,
      latCropped,
    };

    this.latitude = (this.limits.latMin + this.limits.latMax) / 2;
    this.longitude = wraps ? 0 : (this.limits.lonMin + this.limits.lonMax) / 2;
    this.fov = Math.min(this.fov, this.limits.fovMax);
  }

  /** Keep the camera pointed at sphere that carries texture. */
  private clampView() {
    const { latMin, latMax, lonMin, lonMax, fovMax, latCropped } = this.limits;
    this.fov = Math.max(30, Math.min(fovMax, this.fov));

    // Half the visible angle each way, so a crop EDGE stays off-screen rather
    // than merely off-centre. A pole is not an edge -- it is painted, and a
    // full panorama must stay free to look straight up -- so the padding
    // applies only where the image was actually cut.
    const halfV = this.fov / 2;
    const aspect = (this.element.clientWidth || 1) / (this.element.clientHeight || 1);
    const halfH = (Math.atan(Math.tan((this.fov * Math.PI) / 360) * aspect) * 180) / Math.PI;

    this.latitude = clampWithin(this.latitude, latMin, latMax, latCropped ? halfV : 0);
    if (Number.isFinite(lonMin)) {
      this.longitude = clampWithin(this.longitude, lonMin, lonMax, halfH);
    }
  }

  /**
   * Drag to look around, wheel or pinch to zoom.
   *
   * Written by hand rather than pulled from three's OrbitControls: the
   * controls needed here are a small subset, and PhotoSwipe is already
   * listening for the same gestures, so the handlers have to stop propagation
   * in ways a general-purpose controller does not.
   */
  private attachControls(invalidate: () => void) {
    const el = this.element;
    let dragging = false;
    let lastX = 0;
    let lastY = 0;
    let pinch = 0;

    const down = (x: number, y: number) => {
      dragging = true;
      lastX = x;
      lastY = y;
    };

    /**
     * Degrees of rotation per pixel of drag, at the centre of the view, so
     * whatever is under the pointer stays under it.
     *
     * A constant cannot be right here. The camera's focal length in pixels is
     * the element's height over 2tan(fov/2), so the same drag covers a
     * different angle in a tall window than a short one. Measured before this
     * was derived: the picture moved 0.34x the finger in a 400px-tall viewer,
     * 0.51x at 600px and 0.77x at 900px. Width makes no difference, which is
     * the tell -- three's PerspectiveCamera fov is the vertical one.
     */
    const degreesPerPixel = () => {
      const height = this.element.clientHeight || 1;
      const focal = height / 2 / Math.tan((this.fov * Math.PI) / 360);
      return 180 / Math.PI / focal;
    };

    const move = (x: number, y: number) => {
      if (!dragging) return;
      const scale = degreesPerPixel();
      this.longitude -= (x - lastX) * scale;
      this.latitude += (y - lastY) * scale;
      this.clampView();
      lastX = x;
      lastY = y;
      invalidate();
    };

    const up = (e?: Event) => {
      dragging = false;
      if (e && 'pointerId' in e) {
        const id = (e as PointerEvent).pointerId;
        if (el.hasPointerCapture?.(id)) el.releasePointerCapture(id);
      }
    };

    const zoom = (delta: number) => {
      this.fov += delta;
      // Zooming changes how much is visible, so the direction may need to
      // move too -- clampView settles both together.
      this.clampView();
      invalidate();
    };

    const onPointerDown = (e: PointerEvent) => {
      e.stopPropagation();
      // Capture, so a drag that runs past the edge of the viewer keeps
      // turning instead of stopping dead. Without it a quick flick across a
      // small viewer stops at the boundary, which reads as the sphere
      // sticking. Capture also makes 'pointerleave' redundant, so it is no
      // longer bound -- with capture it can fire mid-drag and cancel it.
      try {
        el.setPointerCapture(e.pointerId);
      } catch {
        // Some pointer types cannot be captured; the drag still works.
      }
      down(e.clientX, e.clientY);
    };
    const onPointerMove = (e: PointerEvent) => {
      if (dragging) e.stopPropagation();
      move(e.clientX, e.clientY);
    };
    const onWheel = (e: WheelEvent) => {
      e.preventDefault();
      e.stopPropagation();
      zoom(Math.sign(e.deltaY) * 3);
    };

    const onTouchStart = (e: TouchEvent) => {
      e.stopPropagation();
      if (e.touches.length === 1) {
        down(e.touches[0].clientX, e.touches[0].clientY);
      } else if (e.touches.length === 2) {
        dragging = false;
        pinch = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
      }
    };
    const onTouchMove = (e: TouchEvent) => {
      e.stopPropagation();
      if (e.touches.length === 1) {
        move(e.touches[0].clientX, e.touches[0].clientY);
      } else if (e.touches.length === 2 && pinch > 0) {
        const distance = Math.hypot(
          e.touches[0].clientX - e.touches[1].clientX,
          e.touches[0].clientY - e.touches[1].clientY,
        );
        zoom((pinch - distance) * 0.1);
        pinch = distance;
      }
    };

    el.addEventListener('pointerdown', onPointerDown);
    el.addEventListener('pointermove', onPointerMove);
    el.addEventListener('pointerup', up);
    el.addEventListener('pointercancel', up);
    el.addEventListener('wheel', onWheel, { passive: false });
    el.addEventListener('touchstart', onTouchStart, { passive: true });
    el.addEventListener('touchmove', onTouchMove, { passive: true });
    el.addEventListener('touchend', up);

    this.disposers.push(() => {
      el.removeEventListener('pointerdown', onPointerDown);
      el.removeEventListener('pointermove', onPointerMove);
      el.removeEventListener('pointerup', up);
      el.removeEventListener('pointercancel', up);
      el.removeEventListener('wheel', onWheel);
      el.removeEventListener('touchstart', onTouchStart);
      el.removeEventListener('touchmove', onTouchMove);
      el.removeEventListener('touchend', up);
    });
  }

  /**
   * Replace the texture with a sharper one, once it is available.
   *
   * Safe to leave unawaited: it is a no-op if the view has been torn down in
   * the meantime, and a failure leaves the sphere on the texture it already
   * has rather than blanking it.
   */
  public async upgrade(src: string): Promise<void> {
    try {
      await this.swapTexture?.(src);
    } catch {
      // The preview may not exist, or may be too large for the server to
      // generate. The sphere is already usable; this was an improvement.
    }
  }

  public destroy() {
    this.destroyed = true;
    for (const dispose of this.disposers) dispose();
    this.disposers = [];
    this.element.remove();
  }
}

/**
 * Wires the spherical view into a PhotoSwipe lightbox.
 *
 * Panoramas are *not* shown as spheres automatically. Two reasons: the
 * metadata arrives asynchronously with `imageInfo`, so an automatic switch
 * would flip the slide under the user a moment after it opened; and flattening
 * is still the right default for finding your way around a library. The viewer
 * exposes a toggle instead, and it only appears once the metadata says there
 * is something to toggle.
 */
export default class PanoramaContentSetup {
  private view: PanoramaView | null = null;
  private content: PsContent | null = null;

  constructor(lightbox: PhotoSwipe) {
    lightbox.on('contentActivate', (e) => {
      this.content = (e as unknown as { content: PsContent }).content;
    });
    // Leaving a slide always drops the sphere: keeping one alive off-screen
    // means holding a WebGL context and a full-resolution texture for a photo
    // nobody is looking at.
    lightbox.on('contentDeactivate', () => this.close());
    lightbox.on('destroy', () => this.close());
  }

  public get active(): boolean {
    return this.view !== null;
  }

  public async toggle(): Promise<void> {
    if (this.view) return this.close();

    const content = this.content;
    const info = content ? panoramaInfo(content.data?.photo) : null;

    // Mount on the slide's holder, NOT on content.element.
    //
    // For an image slide content.element *is* the <img> -- appending to it
    // silently does nothing, because an image has no layout children, so the
    // canvas ends up 0x0 behind the flat photo. (PsLivePhoto sidesteps this
    // by replacing content.element with a div of its own during contentLoad;
    // that is not open to us, because whether a photo is a panorama depends
    // on imageInfo, which has not arrived that early.)
    //
    // The holder is also the right layer: the zoom-wrap between it and the
    // image carries PhotoSwipe's pan and zoom transform, which would drag
    // the sphere around instead of letting it do its own looking.
    const holder = content?.slide?.holderElement;
    if (!holder || !info) return;

    // content.data.src is the Nextcloud preview at screen size, not the
    // original -- see getItemData() in Viewer.vue. That is the right texture
    // to use, and deliberately so:
    //
    //  - The crop is applied as RATIOS (left / fullWidth, and so on), so a
    //    downscaled preview maps onto the sphere exactly as the full-size
    //    image would. Nothing here depends on the pixel counts matching.
    //  - When a preview provider renders the panorama from a camera container,
    //    the original may not be a panorama at all (see
    //    originalIsTheSameImage), and would put fisheye circles on the sphere.
    const view = await PanoramaView.create(content.data.src, info);

    // The slide may have been left while the texture was decoding.
    if (this.content !== content) {
      view.destroy();
      return;
    }

    holder.appendChild(view.element);
    this.view = view;

    // Then ask for a sharper one. A flat photo is shown at screen size, but a
    // sphere spreads the same pixels over 360 degrees and the viewer can zoom
    // into a fifth of that, so the screen-sized preview is the wrong size here
    // by roughly the zoom factor. Unawaited on purpose: the sphere is already
    // interactive, and the server may take seconds to render this one.
    const photo = content.data?.photo;
    if (photo) {
      view.upgrade(utils.getPreviewUrl({ photo, size: [PANORAMA_TEXTURE, PANORAMA_TEXTURE] }));
    }
  }

  public close(): void {
    this.view?.destroy();
    this.view = null;
  }
}
