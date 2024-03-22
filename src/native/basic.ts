import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { nativex } from './api';

/**
 * @returns Whether the native interface is available.
 */
export function has() {
  return !!nativex;
}

/**
 * Change the theme color of the app to default.
 */
export async function setTheme(color?: string, dark?: boolean) {
  if (!has()) return;

  color ??= getComputedStyle(document.body).getPropertyValue('--color-main-background');
  dark ??=
    (document.body.hasAttribute('data-theme-default') && window.matchMedia('(prefers-color-scheme: dark)').matches) ||
    document.body.hasAttribute('data-theme-dark') ||
    document.body.hasAttribute('data-theme-dark-highcontrast');
  nativex?.setThemeColor?.(color, dark);
}

/**
 * Play touch sound.
 */
export async function playTouchSound() {
  nativex?.playTouchSound?.();
}

/**
 * Log out from Nextcloud and pass ahead.
 */
export async function logout() {
  try {
    await axios.get(generateUrl('logout'));
  } catch (error) {
    // weird ...
  } finally {
    if (!has()) window.location.reload();
    nativex?.logout();
  }
}

/**
 * Add current origin to URL if doesn't have any protocol or origin.
 */
export function addOrigin(url: string) {
  return url.match(/^(https?:)?\/\//)
    ? url
    : url.startsWith('/')
      ? `${location.origin}${url}`
      : `${location.origin}/${url}`;
}
