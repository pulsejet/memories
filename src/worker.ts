/** Set the receiver function for a worker */
export function workerExport(handlers: Record<string, (...data: any) => Promise<any>>): void {
  /** Promise API for web worker */
  self.onmessage = async ({
    data,
  }: {
    data: {
      id: number;
      name: string;
      args: any[];
    };
  }) => {
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
  const promises = new Map<number, { resolve: any; reject: any }>();

  worker.onmessage = ({ data }: { data: any }) => {
    const { id, resolve, reject } = data;
    if (resolve) promises.get(id)?.resolve(resolve);
    if (reject) promises.get(id)?.reject(reject);
    promises.delete(id);
  };

  type PromiseFun = (...args: any) => Promise<any>;
  return function importer<F extends PromiseFun>(name: string) {
    return async function fun(...args: Parameters<F>) {
      return await new Promise<ReturnType<Awaited<F>>>((resolve, reject) => {
        const id = Math.random();
        promises.set(id, { resolve, reject });
        worker.postMessage({ id, name, args });
      });
    };
  };
}
