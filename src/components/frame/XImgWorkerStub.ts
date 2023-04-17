/**
 * This is a hack to make webpack work with web workers.
 * It is not a real worker, but a stub that loads the real worker.
 * This way we can set the public path to the current directory
 * before the actual worker loads, which in turn allows the worker
 * to load other webpack chunks.
 */

const pathname = self.location.pathname;
__webpack_public_path__ = pathname.substring(0, pathname.lastIndexOf("/") + 1);

const missedQueue = [];
self.onmessage = function (val: any) {
  missedQueue.push(val);
};

import("./XImgWorker").then(function () {
  missedQueue.forEach((data: any) => self.onmessage(data));
});
