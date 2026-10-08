import { getCurrentUser } from '@nextcloud/auth';

/**
 * Get the current user UID
 */
export const uid = String(getCurrentUser()?.uid || String()) || null;

/**
 * Check if the current user is an admin
 */
export const isAdmin = Boolean(getCurrentUser()?.isAdmin);
