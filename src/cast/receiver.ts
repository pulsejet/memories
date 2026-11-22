/**
 * Custom Cast receiver running on the TV.
 * Shows whatever the sender's viewer is showing, with crossfades.
 * Loaded by templates/cast-receiver.php; does not depend on Vue or Nextcloud.
 */
import {
  CAST_NAMESPACE,
  CAST_PROTOCOL_VERSION,
  type CastReceiverMessage,
  type CastSenderMessage,
  type Versioned,
} from './protocol';

/** Close the app if no sender reconnects within this time */
const ORPHAN_TIMEOUT_MS = 10 * 60 * 1000;
const MAX_PRELOAD = 6;

const $ = <T extends HTMLElement>(id: string) => document.getElementById(id) as T;
const layers = [$<HTMLImageElement>('img-a'), $<HTMLImageElement>('img-b')];
const video = $<HTMLVideoElement>('video');
const idle = $('idle');
const info = $('info');
const infoDate = $('info-date');
const infoPlace = $('info-place');

const context = cast.framework.CastReceiverContext.getInstance();

let currentKey: string | null = null;
let front = 0;
let orphanTimer = 0;
const preloaded = new Map<string, HTMLImageElement>();

function sendEvent(msg: CastReceiverMessage) {
  const data: Versioned<CastReceiverMessage> = { v: CAST_PROTOCOL_VERSION, ...msg };
  context.sendCustomMessage(CAST_NAMESPACE, undefined, data);
}

function setInfo(date?: string, place?: string) {
  infoDate.textContent = date ?? '';
  infoPlace.textContent = place ?? '';
  info.classList.toggle('visible', !!(date || place));
}

/** Let the server pick a preview size fitting this screen, unless the sender chose one */
function sized(url: string) {
  if (new URL(url).searchParams.has('size')) return url;
  return `${url}&size=${Math.max(window.innerWidth, window.innerHeight)}`;
}

function preload(urls: string[]) {
  for (const url of urls.slice(0, MAX_PRELOAD).map(sized)) {
    if (preloaded.has(url)) continue;
    const img = new Image();
    img.src = url;
    preloaded.set(url, img);
  }

  // Drop the oldest entries
  while (preloaded.size > MAX_PRELOAD * 2) {
    preloaded.delete(preloaded.keys().next().value!);
  }
}

function stopVideo() {
  video.pause();
  video.removeAttribute('src');
  video.load();
  video.classList.remove('visible');
}

function showImage(key: string, url: string) {
  const back = layers[1 - front];
  const swap = async () => {
    // Decode before the crossfade so it does not stutter
    await back.decode().catch(() => {});
    if (currentKey !== key) return;
    stopVideo();
    back.classList.add('visible');
    layers[front].classList.remove('visible');
    front = 1 - front;
  };
  back.onload = swap;
  back.onerror = () => {
    console.error('Failed to load image', url);
    if (currentKey === key) setInfo();
  };

  // Setting the same src again does not fire load
  url = sized(url);
  if (back.src === url && back.complete) {
    swap();
  } else {
    back.src = url;
  }
}

function showVideo(key: string, url: string) {
  layers.forEach((l) => l.classList.remove('visible'));
  video.onended = () => sendEvent({ type: 'ended', key });
  video.onerror = () => sendEvent({ type: 'ended', key }); // e.g. unsupported codec; skip ahead
  video.src = url;
  video.classList.add('visible');
  video.play().catch(() => {});
}

function onMessage(msg: Versioned<CastSenderMessage>) {
  // Unknown message types may come from newer senders
  if (msg.type !== 'show') return;

  idle.classList.remove('visible');
  setInfo(msg.date, msg.place);

  // Same item, e.g. metadata got loaded
  if (msg.key !== currentKey) {
    currentKey = msg.key;
    if (msg.video) {
      showVideo(msg.key, msg.url);
    } else {
      showImage(msg.key, msg.url);
    }
  }

  preload(msg.preload);
}

context.addCustomMessageListener(CAST_NAMESPACE, (event) => onMessage(event.data as Versioned<CastSenderMessage>));

context.addEventListener(cast.framework.system.EventType.SENDER_CONNECTED, () => {
  window.clearTimeout(orphanTimer);
});

context.addEventListener(cast.framework.system.EventType.SENDER_DISCONNECTED, () => {
  if (context.getSenders().length) return;
  window.clearTimeout(orphanTimer);
  orphanTimer = window.setTimeout(() => context.stop(), ORPHAN_TIMEOUT_MS);
});

context.start({
  customNamespaces: { [CAST_NAMESPACE]: cast.framework.system.MessageType.JSON },
  disableIdleTimeout: true,
  skipPlayersLoad: true,
});
