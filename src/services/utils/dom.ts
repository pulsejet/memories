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
