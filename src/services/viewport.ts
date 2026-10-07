import { reactive } from 'vue';

/**
 * Cached window dimensions shared across the app.
 * Updated on resize (debounced) below, so prefer this
 * over reading window.innerWidth/Height in reactive contexts.
 */
export const windowDims = reactive({
  /** Current viewport width in px */
  width: window.innerWidth,
  /** Current viewport height in px */
  height: window.innerHeight,
  /** True when the viewport is at mobile size (width <= 768px) */
  isMobile: window.innerWidth <= 768,
});

/** Keep the cached dims in sync with the viewport (debounced). */
let resizeTimer = 0;
window.addEventListener('resize', () => {
  if (resizeTimer) window.clearTimeout(resizeTimer);
  resizeTimer = window.setTimeout(() => {
    windowDims.width = window.innerWidth;
    windowDims.height = window.innerHeight;
    windowDims.isMobile = window.innerWidth <= 768;
  }, 100);
});

/**
 * Check if a element is at least partially in the viewport.
 * @param el Element to check
 */
export function isPartiallyInViewport(el: HTMLElement): boolean {
  const boundingRect = el.getBoundingClientRect();
  return (
    boundingRect.top < windowDims.height &&
    boundingRect.bottom > 0 &&
    boundingRect.left < windowDims.width &&
    boundingRect.right > 0
  );
}
