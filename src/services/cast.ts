import { reactive, readonly } from 'vue';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { waitForConfig } from '@services/user-config';
import * as nativex from '@native';

const SDK_URL = 'https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1';

/** Refresh tokens this many seconds before they expire */
const TOKEN_MARGIN_S = 60 * 60;

export type CastItem = {
  fileid: number;
  video: boolean;
  mime?: string;
  date?: string;
  place?: string;
};

const _state = reactive({
  /** A cast device was found on the network */
  available: false,
  /** A cast session is active */
  connected: false,
  /** Friendly name of the connected device */
  device: '',
});
export const state = readonly(_state);

let loading: Promise<void> | null = null;
let showSeq = 0;
/** Key of the last video the receiver finished */
let endedKey: string | null = null;
/** Last item loaded on the receiver */
let last: { key: string; date?: string; place?: string } | null = null;
const tokens = new Map<number, { token: string; expiry: number }>();
const endedWaiters = new Map<string, () => void>();

/**
 * Load the Cast sender SDK once.
 * Casting is disabled in the native app (WebView has no Cast support).
 */
export function init(): Promise<void> {
  return (loading ??= (async () => {
    await waitForConfig();
    if (nativex.has() || !window.isSecureContext) return;

    await new Promise<void>((resolve) => {
      window.__onGCastApiAvailable = (available: boolean) => {
        if (available) setup();
        resolve();
      };

      const script = document.createElement('script');
      script.src = SDK_URL;
      script.async = true;
      script.onerror = () => resolve();
      document.head.appendChild(script);
    });
  })());
}

function setup() {
  const fw = cast.framework;
  const ctx = fw.CastContext.getInstance();
  ctx.setOptions({
    receiverApplicationId: chrome.cast.media.DEFAULT_MEDIA_RECEIVER_APP_ID,
    autoJoinPolicy: chrome.cast.AutoJoinPolicy.ORIGIN_SCOPED,
  });

  const update = () => {
    const castState = ctx.getCastState();
    const session = ctx.getCurrentSession();
    _state.available = castState !== fw.CastState.NO_DEVICES_AVAILABLE;
    _state.connected = castState === fw.CastState.CONNECTED && !!session;
    _state.device = session?.getCastDevice().friendlyName ?? '';
  };

  ctx.addEventListener(fw.CastContextEventType.CAST_STATE_CHANGED, update);
  ctx.addEventListener(fw.CastContextEventType.SESSION_STATE_CHANGED, (event) => {
    if (event.sessionState === fw.SessionState.SESSION_ENDED) {
      last = null;
      endedWaiters.forEach((resolve) => resolve());
      endedWaiters.clear();
    }
    update();
  });

  update();
}

function session() {
  return window.cast?.framework?.CastContext.getInstance().getCurrentSession() ?? null;
}

function markEnded(key: string) {
  endedKey = key;
  endedWaiters.get(key)?.();
  endedWaiters.delete(key);
}

/** Open the device picker and start a session */
export async function connect() {
  await init();
  try {
    await cast.framework.CastContext.getInstance().requestSession();
  } catch {
    // user cancelled the picker
  }
}

/** End the session and close the receiver app */
export function disconnect() {
  window.cast?.framework?.CastContext.getInstance().endCurrentSession(true);
}

/** Start or stop casting */
export function toggle() {
  if (_state.connected) {
    disconnect();
  } else {
    connect();
  }
}

/** Make sure we have valid tokens for these files */
async function ensureTokens(fileids: number[]) {
  const now = Date.now() / 1000;
  const missing = fileids.filter((id) => (tokens.get(id)?.expiry ?? 0) - now < TOKEN_MARGIN_S);
  if (!missing.length) return;

  const res = await axios.post<{ tokens: Record<string, string>; expiry: number }>(API.CAST_TOKENS(), {
    files: missing,
  });
  for (const [id, token] of Object.entries(res.data.tokens)) {
    tokens.set(Number(id), { token, expiry: res.data.expiry });
  }
}

/**
 * Show a photo or video on the TV.
 * @param item item to show now
 * @param nearby file IDs to fetch tokens for in the background
 */
export async function show(item: CastItem, nearby: number[] = []) {
  if (!session()) return;
  const seq = ++showSeq;

  try {
    await ensureTokens([item.fileid]);
  } catch (e) {
    console.error('Failed to get cast tokens', e);
    return;
  }

  // Have tokens ready before the user navigates there
  ensureTokens(nearby).catch(() => {});

  // Another item was requested in the meantime
  if (seq !== showSeq) return;

  const token = tokens.get(item.fileid)?.token;
  if (!token) return;
  const url = new URL(API.CAST_FILE(item.fileid, token), window.location.href).toString();

  const key = String(item.fileid);
  if (key !== endedKey) endedKey = null;

  // Reloading shows a spinner; only reload images when metadata arrived late
  if (last?.key === key && (item.video || (last.date === item.date && last.place === item.place))) return;
  last = { key, date: item.date, place: item.place };

  const { media } = chrome.cast;
  let info: chrome.cast.media.MediaInfo;
  if (item.video) {
    // QuickTime is mostly H.264 in an MP4-compatible container
    const mime = !item.mime || item.mime === 'video/quicktime' ? 'video/mp4' : item.mime;
    info = new media.MediaInfo(url, mime);
    const meta = new media.GenericMediaMetadata();
    meta.title = item.date;
    meta.subtitle = item.place;
    info.metadata = meta;
  } else {
    info = new media.MediaInfo(url, 'image/jpeg');
    const meta = new media.PhotoMediaMetadata();
    meta.title = item.date;
    meta.location = item.place;
    info.metadata = meta;
  }

  try {
    const s = session();
    await s?.loadMedia(new media.LoadRequest(info));

    const loaded = s?.getMediaSession();
    loaded?.addUpdateListener(() => {
      if (loaded.playerState === media.PlayerState.IDLE && loaded.idleReason) markEnded(key);
    });
  } catch (e) {
    console.error('Failed to load media on cast device', e);
    markEnded(key); // skip ahead in slideshows
  }
}

/** Resolves when the receiver finished playing this video (or the session ended) */
export function waitVideoEnd(fileid: number): Promise<void> {
  const key = String(fileid);
  if (endedKey === key || !session()) return Promise.resolve();
  return new Promise((resolve) => endedWaiters.set(key, resolve));
}
