import { UploadCancelledError, UploadFailedError, UploadStatus } from '@nextcloud/files/upload';
export { getUploader, Uploader } from '@nextcloud/files/upload';
import type { Uploader } from '@nextcloud/files/upload';

/**
 * Upload a file and wait until it is done.
 * Uploader.upload only waits until the file is queued and never reports failures.
 * The uploader resets once idle, cancelling any new upload that was started before that.
 */
export async function uploadFile(uploader: Uploader, destination: string, file: File, signal: AbortSignal) {
  const reset = new Promise((resolve) => uploader.addEventListener('reset', resolve, { once: true }));
  const upload = await uploader.upload(destination, file, { signal });
  await reset;

  if (upload.status === UploadStatus.CANCELLED) {
    throw new UploadCancelledError();
  } else if (upload.status !== UploadStatus.FINISHED) {
    throw new UploadFailedError();
  }
}
