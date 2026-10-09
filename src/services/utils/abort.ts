/**
 * Shared AbortSignal / AbortController helpers.
 *
 * Pattern: each async load captures the signal for its generation, passes it to services
 * (axios, webdav, cache), then checks it after each await. Starting new work calls renew(),
 * which aborts the previous generation so stale results are dropped instead of applied.
 */

/** True if the error is a cancellation (DOM AbortError or axios CanceledError). */
export function isAbortError(err: unknown): boolean {
  if (!err || typeof err !== 'object') return false;
  const e = err as { name?: unknown; code?: unknown; message?: unknown };
  if (e.name === 'AbortError' || e.name === 'CanceledError') return true;
  if (e.code === 'ERR_CANCELED' || e.code === 'ERR_ABORTED') return true;
  // axios v1 CanceledError message is "canceled"
  if (typeof e.message === 'string' && e.message === 'canceled') return true;
  return false;
}

/** Throw an AbortError if the signal is aborted. No-op for a nullish signal. */
export function throwIfAborted(signal: AbortSignal | null | undefined): void {
  signal?.throwIfAborted();
}

/** Swallow abort errors from a fire-and-forget promise, rethrowing anything else. */
export async function ignoreAbort<T>(promise: Promise<T>): Promise<T | void> {
  try {
    return await promise;
  } catch (e) {
    if (!isAbortError(e)) throw e;
  }
}

/**
 * Race a promise against a signal. Rejects with AbortError if the signal aborts first,
 * else settles as the promise. Useful for non-abortable awaits (timers, third-party
 * promises, vueuse until()).
 */
export function raceWithAbort<T>(signal: AbortSignal | null | undefined, promise: Promise<T>): Promise<T> {
  if (!signal) return promise;

  return new Promise<T>((resolve, reject) => {
    const onAbort = () => {
      signal.removeEventListener('abort', onAbort);
      reject(signal.reason ?? new DOMException('Aborted', 'AbortError'));
    };

    if (signal.aborted) {
      return onAbort();
    }

    signal.addEventListener('abort', onAbort, { once: true });

    promise.then(
      (value) => {
        signal.removeEventListener('abort', onAbort);
        resolve(value);
      },
      (error) => {
        signal.removeEventListener('abort', onAbort);
        reject(error);
      },
    );
  });
}

/** Options bag for cancellable service calls. Prefer this over a positional signal param. */
export type AbortOpts = {
  signal?: AbortSignal;
};
