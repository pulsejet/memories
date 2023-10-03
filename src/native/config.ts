import { NAPI, nativex } from './api';

/** Setting of whether a local folder is enabled */
export type LocalFolderConfig = {
  id: string;
  name: string;
  enabled: boolean;
};

/**
 * Set list of local folders configuration.
 */
export function setLocalFolders(config: LocalFolderConfig[]) {
  return nativex?.configSetLocalFolders(JSON.stringify(config));
}

/**
 * Get list of local folders configuration.
 * Should be called only if NativeX is available.
 */
export function getLocalFolders() {
  return JSON.parse(nativex?.configGetLocalFolders?.() ?? '[]') as LocalFolderConfig[];
}

/**
 * Check if the user has allowed media access.
 */
export function configHasMediaPermission() {
  return nativex?.configHasMediaPermission?.() ?? false;
}

/**
 * Allow access to media.
 */
export async function configAllowMedia(val: boolean = true) {
  return await fetch(NAPI.CONFIG_ALLOW_MEDIA(val));
}
