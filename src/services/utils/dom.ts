/**
 * Add event listener to DOMContentLoaded and fire
 * callback immediately if the event has already fired.
 */
export function onDOMLoaded(callback: () => void) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', callback);
  } else {
    window.queueMicrotask(callback);
  }
}

/**
 * Run a callback, logging and swallowing any error it throws.
 * Async rejections (e.g. blocked autoplay) are logged and swallowed too.
 * Returns the callback's result, or null if it threw.
 */
export function runCatching<T>(callback: () => T): T | null {
  try {
    const result = callback();
    if (result instanceof Promise) {
      result.catch((e) => console.warn('runCatching: ignored error', e));
    }
    return result;
  } catch (e) {
    console.warn('runCatching: ignored error', e);
    return null;
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

let _hasWebGL: boolean | undefined;

/** Whether a WebGL context can be created. */
export function hasWebGL(): boolean {
  if (_hasWebGL === undefined) {
    const gl = document.createElement('canvas').getContext('webgl');
    gl?.getExtension('WEBGL_lose_context')?.loseContext();
    _hasWebGL = !!gl;
  }
  return _hasWebGL;
}
