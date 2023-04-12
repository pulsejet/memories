/** Set the receiver function for a worker */
export function workerExport(handlers: {
  [key: string]: (...data: any) => Promise<any>;
}) {
  /** Promise API for web worker */
  self.onmessage = async ({ data }) => {
    try {
      const handler = handlers[data.name];
      if (!handler) throw new Error(`No handler for type ${data.name}`);
      const res = await handler.apply(self, data.args);
      self.postMessage({
        id: data.id,
        resolve: res,
      });
    } catch (e) {
      self.postMessage({
        id: data.id,
        reject: e.message,
      });
    }
  };
}

/** Get the CALL function for a worker. Call this only once. */
export function workerImporter(worker: Worker) {
  const promises: { [id: string]: any } = {};
  worker.onmessage = ({ data }: { data: any }) => {
    const { id, resolve, reject } = data;
    if (resolve) promises[id].resolve(resolve);
    if (reject) promises[id].reject(reject);
    delete promises[id];
  };
  return function importer<F extends (...args: any) => Promise<any>>(
    name: string
  ): (...args: Parameters<F>) => ReturnType<F> {
    return function fun(...args: any) {
      return new Promise((resolve, reject) => {
        const id = Math.random();
        promises[id] = { resolve, reject };
        worker.postMessage({ id, name, args });
      });
    } as any;
  };
}
