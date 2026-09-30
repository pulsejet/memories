/**
 * Chrome on Android sometimes swallows the compatibility mouse events
 * (and thus the click) of the first tap after a touch drag handled with
 * preventDefault, while touch events are delivered fine.
 * This patch qualifies taps on covered buttons and fires the click
 * itself, suppressing the native compatibility click with preventDefault
 * on touchend. The synthetic click runs inside the trusted touchend, so
 * transient activation is kept.
 *
 * Only touchend must be non-passive; the other handlers only record.
 * Attach with Vue, e.g. on the viewer container:
 *
 *   @touchstart.passive="tapPatch.onTouchStart"
 *   @touchend="tapPatch.onTouchEnd"
 *   @touchcancel.passive="tapPatch.onTouchCancel"
 */

export type TapClickPatchOptions = {
  /** Button containers to cover, matched with closest() */
  containers: string[];
  /** Max finger travel in px to still count as a tap */
  slopPx?: number;
  /** Max press duration in ms to still count as a tap */
  timeoutMs?: number;
};

type PendingTap = {
  startX: number;
  startY: number;
  startTime: number;
  button: HTMLElement;
} | null;

/**
 * Create tap-to-click handlers for buttons inside the given containers.
 * Only single-finger touch taps are patched; mouse, pen, multi-touch
 * and keyboard keep the native path.
 */
export function makeTapPatch({ containers, slopPx = 12, timeoutMs = 600 }: TapClickPatchOptions) {
  const buttonSelector = containers.map((c) => `${c} button`).join(', ');
  let pendingTap: PendingTap = null;

  const onTouchStart = (e: TouchEvent) => {
    if (e.touches.length !== 1) {
      pendingTap = null;
      return;
    }
    const touch = e.touches[0];
    const button = (e.target as Element | null)?.closest(buttonSelector) as HTMLElement | null;
    pendingTap = button ? { startX: touch.clientX, startY: touch.clientY, startTime: performance.now(), button } : null;
  };

  const onTouchEnd = (e: TouchEvent) => {
    const tap = pendingTap;
    pendingTap = null;
    if (!tap || e.touches.length !== 0 || !document.contains(tap.button)) return;
    const touch = e.changedTouches[0];
    if (!touch || performance.now() - tap.startTime > timeoutMs) return;
    if (Math.hypot(touch.clientX - tap.startX, touch.clientY - tap.startY) > slopPx) return;
    if ((e.target as Element | null)?.closest('button') !== tap.button) return;

    // Swallow the native compatibility click and fire our own.
    e.preventDefault();
    tap.button.focus({ preventScroll: true });
    tap.button.click();
  };

  const onTouchCancel = () => {
    pendingTap = null;
  };

  return { onTouchStart, onTouchEnd, onTouchCancel };
}
