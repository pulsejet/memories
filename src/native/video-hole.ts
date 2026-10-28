import { nxAsync } from './api';

/**
 * Web-to-native commands for the hole video player.
 * Sent as a single JSON string over the async bridge.
 */
export type NativeVideoCommand =
  | { action: 'play'; url: string; loop: boolean }
  | { action: 'stop'; url: string }
  | { action: 'pause'; url: string }
  | { action: 'seek'; url: string; position: number }
  | { action: 'volume'; url: string; volume: number }
  | { action: 'mute'; url: string; muted: boolean }
  | { action: 'rate'; url: string; rate: number };

/** Send one control command to the native hole video player. */
export function videoCommand(cmd: NativeVideoCommand): void {
  nxAsync('nativeVideoCommand')?.(JSON.stringify(cmd));
}
