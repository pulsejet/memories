import { nxAsync } from './api';

/**
 * Web-to-native commands for the hole video player.
 * Sent as a single JSON string over the async bridge.
 */
export type NativeVideoCommand =
  | { action: 'play'; url: string; loop: boolean }
  | { action: 'pause' }
  | { action: 'seek'; position: number }
  | { action: 'volume'; volume: number }
  | { action: 'mute'; muted: boolean }
  | { action: 'rate'; rate: number };

/** Send one control command to the native hole video player. */
export function videoCommand(cmd: NativeVideoCommand): void {
  nxAsync('nativeVideoCommand')?.(JSON.stringify(cmd));
}
