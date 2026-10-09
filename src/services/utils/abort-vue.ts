import { onBeforeUnmount } from 'vue';

export * from './abort';

/** Plain (non-Vue) abort scope. Owns one controller. */
export function createAbortScope() {
  let controller = new AbortController();
  return {
    /** Signal for the current generation. */
    get signal(): AbortSignal {
      return controller.signal;
    },
    /** Whether the current generation is aborted. */
    get aborted(): boolean {
      return controller.signal.aborted;
    },
    /** Abort the current generation. */
    abort(reason?: unknown) {
      controller.abort(reason);
    },
    /** Abort the current generation and start a fresh one. Returns the new signal. */
    renew(): AbortSignal {
      controller.abort();
      controller = new AbortController();
      return controller.signal;
    },
    /** Throw if the current generation is aborted. */
    throwIfAborted() {
      controller.signal.throwIfAborted();
    },
  };
}

/**
 * Vue composable owning an abort scope. Aborts on unmount; call renew() when starting
 * new work (refresh, param change) to cancel the in-flight generation.
 */
export function useAbort() {
  const scope = createAbortScope();
  onBeforeUnmount(() => scope.abort());
  return scope;
}
