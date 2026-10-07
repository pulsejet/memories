import { has as hasNativeX } from '@native/api';

/**
 * Check if the provided Axios Error is a network error.
 */
export function isNetworkError(error: any) {
  return error?.code === 'ERR_NETWORK' || (hasNativeX() && error?.response?.status === 504);
}
