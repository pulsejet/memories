import { reactive, readonly } from 'vue';
import axios from '@nextcloud/axios';

import { API } from '@services/API';
import { config, waitForConfig } from '@services/user-config';
import * as nativex from '@native';

import {
  CAST_NAMESPACE,
  CAST_PROTOCOL_VERSION,
  type CastReceiverMessage,
  type CastSenderMessage,
  type Versioned,
} from '../cast/protocol';

const SDK_URL = 'https://www.gstatic.com/cv/js/sender/v1/cast_sender.js?loadCastFramework=1';

/** Refresh tokens this many seconds before they expire */
const TOKEN_MARGIN_S = 60 * 60;

/** Screen size sent to the server per quality; it picks a 1024px or full preview */
const QUALITY_SIZE = { standard: 1280, high: 3840 };

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
/** Last item loaded on Google's default receiver */
let lastDefault: { key: string; date?: string; place?: string } | null = null;
const tokens = new Map<number, { token: string; expiry: number }>();
const endedWaiters = new Map<string, () => void>();

/**
 * Use our own receiver if an app ID is configured.
 * Otherwise fall back to Google's default media receiver,
 * which needs no registration but cannot be customized.
 */
function isCustom() {
  return !!config.cast_app_id;
}

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
    receiverApplicationId: config.cast_app_id || chrome.cast.media.DEFAULT_MEDIA_RECEIVER_APP_ID,
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
    const { SESSION_STARTED, SESSION_RESUMED, SESSION_ENDED } = fw.SessionState;
    if (event.sessionState === SESSION_STARTED || event.sessionState === SESSION_RESUMED) {
      if (isCustom()) event.session.addMessageListener(CAST_NAMESPACE, onMessage);
    } else if (event.sessionState === SESSION_ENDED) {
      lastDefault = null;
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

function onMessage(_ns: string, data: string) {
  let msg: Versioned<CastReceiverMessage>;
  try {
    msg = JSON.parse(data);
  } catch {
    return;
  }

  if (msg.type === 'ended') markEnded(msg.key);
}

function markEnded(key: string) {
  endedKey = key;
  endedWaiters.get(key)?.();
  endedWaiters.delete(key);
}

async function send(msg: CastSenderMessage) {
  const data: Versioned<CastSenderMessage> = { v: CAST_PROTOCOL_VERSION, ...msg };
  await session()?.sendMessage(CAST_NAMESPACE, data);
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

function fileUrl(fileid: number): string | null {
  const token = tokens.get(fileid)?.token;
  if (!token) return null;

  // Automatic: our receiver appends its own screen size
  let quality = config.cast_quality;
  if (quality === 'auto' && !isCustom()) quality = 'standard';
  const size = quality === 'auto' ? undefined : QUALITY_SIZE[quality];

  return new URL(API.CAST_FILE(fileid, token, size), window.location.href).toString();
}

/**
 * Show a photo or video on the TV.
 * @param item item to show now
 * @param upcoming file IDs the receiver should preload
 * @param nearby file IDs to fetch tokens for in the background
 */
export async function show(item: CastItem, upcoming: number[] = [], nearby: number[] = []) {
  if (!session()) return;
  const seq = ++showSeq;

  try {
    await ensureTokens([item.fileid, ...upcoming]);
  } catch (e) {
    console.error('Failed to get cast tokens', e);
    return;
  }

  // Have tokens ready before the user navigates there
  ensureTokens(nearby).catch(() => {});

  // Another item was requested in the meantime
  if (seq !== showSeq) return;

  const url = fileUrl(item.fileid);
  if (!url) return;

  const key = String(item.fileid);
  if (key !== endedKey) endedKey = null;

  if (!isCustom()) return showDefault(key, url, item);

  await send({
    type: 'show',
    key,
    url,
    video: item.video,
    date: item.date,
    place: item.place,
    preload: upcoming.map(fileUrl).filter((u): u is string => !!u),
  });
}

/** Load an item on Google's default media receiver */
async function showDefault(key: string, url: string, item: CastItem) {
  // Reloading shows a spinner; only reload images when metadata arrived late
  const last = lastDefault;
  if (last?.key === key && (item.video || (last.date === item.date && last.place === item.place))) return;
  lastDefault = { key, date: item.date, place: item.place };

  const { media } = chrome.cast;
  let info: chrome.cast.media.MediaInfo;
  if (item.video) {
    // QuickTime is mostly H.264 in an MP4-compatible container
    const mime = !item.mime || item.mime === 'video/quicktime' ? 'video/mp4' : item.mime;
    info = new media.MediaInfo(url, mime);
    info.metadata = new media.GenericMediaMetadata();
    info.metadata.title = item.date;
    info.metadata.subtitle = item.place;
  } else {
    info = new media.MediaInfo(url, 'image/jpeg');
    info.metadata = new media.PhotoMediaMetadata();
    info.metadata.title = item.date;
    info.metadata.location = item.place;
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
