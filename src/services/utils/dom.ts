/**
 * Add event listener to DOMContentLoaded and fire
 * callback immediately if the event has already fired.
 */
export function onDOMLoaded(callback: () => void) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', callback);
  } else {
    setTimeout(callback, 0);
  }
}

/**
 * Unwrap a CustomEvent detail payload. Vue custom elements dispatch
 * emit args wrapped in an array, so normalize both shapes.
 */
export function customEventDetail<T>(event: Event): T | undefined {
  const detail = (event as CustomEvent<T | T[]>).detail;
  return Array.isArray(detail) ? detail[0] : detail;
}
