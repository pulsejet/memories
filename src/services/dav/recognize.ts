import axios from '@nextcloud/axios';
import { API } from '@services/API';
import { config, waitForConfig } from '@services/user-config';

/**
 * One-shot fetch of the Recognize API key.
 *
 * Calls back with the API key, or undefined if Recognize
 * is disabled or unavailable.
 */
export function onRecognizeApiKeyUpdate(callback: (key?: string) => void): void {
  (async (): Promise<string | undefined> => {
    await waitForConfig();
    if (!config.recognize_enabled) {
      return undefined;
    }

    try {
      const res = await axios.get<{ apiKey: string }>(API.RECOGNIZE_API_KEY());
      return res.data?.apiKey || undefined;
    } catch (err) {
      console.error('Failed to fetch Recognize API key', err);
      return undefined;
    }
  })().then(callback);
}
