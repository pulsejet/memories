/**
 * Data sent from main thread to worker.
 */
type CommRequest = {
  reqid: number;
  name: string;
  args: any[];
};

/**
 * Data sent from worker to main thread.
 */
type CommResult = {
  reqid: number;
  resolve?: any;
  reject?: string;
};

/**
 * Export methods from a worker to the main thread.
 *
 * @param handlers Object with methods to export
 *
 * @example
 * ```ts
 * // my-worker.ts
 * function foo() { return 'bar'; }
 *
 * async function asyncFoo() { return 'bar'; }
 *
 * export default exportWorker({
 *  foo,
 *  asyncFoo,
 *  inline: () => 'bar',
 * });
 */
export function exportWorker<T extends { [name: string]: Function }>(handlers: T): T {
  self.onmessage = async ({ data }: { data: CommRequest }) => {
    try {
      // Get handler from registrations
      const handler = handlers[data.name];
      if (!handler) throw new Error(`[BUG] No handler for type ${data.name}`);

      // Run handler
      let result = handler.apply(self, data.args);
      if (result instanceof Promise) {
        result = await result;
      }

      // Success - post back to main thread
      self.postMessage({ reqid: data.reqid, resolve: result } as CommResult);
    } catch (e) {
      // Error - post back rejection
      self.postMessage({ reqid: data.reqid, reject: e.message } as CommResult);
    }
  };

  return null as unknown as T;
}

/**
 * Import a worker exported with `exportWorker`.
 *
 * @param worker Worker to import
 *
 * @example
 * ```ts
 * // main.ts
 * import type MyWorker from './my-worker.ts';
 *
 * const worker = importWorker<typeof MyWorker>(new Worker(new URL('./XImgWorkerStub.ts', import.meta.url)));
 *
 * async (() => {
 *   // all methods are async
 *   console.assert(await worker.foo() === 'bar');
 *   console.assert(await worker.asyncFoo() === 'bar');
 *   console.assert(await worker.inline() === 'bar');
 * });
 */
export function importWorker<T>(worker: Worker) {
  const promises = new Map<number, { resolve: Function; reject: Function }>();

  // Handle messages from worker
  worker.onmessage = ({ data }: { data: CommResult }) => {
    const { reqid, resolve, reject } = data;
    if (resolve) promises.get(reqid)?.resolve(resolve);
    if (reject) promises.get(reqid)?.reject(reject);
    promises.delete(reqid);
  };

  // Create proxy to call worker methods
  const proxy = new Proxy(worker, {
    get(target: Worker, name: string) {
      return async function wrapper(...args: any[]) {
        return await new Promise((resolve, reject) => {
          const reqid = Math.random();
          promises.set(reqid, { resolve, reject });
          target.postMessage({ reqid, name, args } as CommRequest);
        });
      };
    },
  });

  return proxy as T;
}
