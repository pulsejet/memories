// Probes browser-playable video codecs via the Media Capabilities API.
// The result is sent to go-vod as ?codecs=, which serves stream copy
// instead of transcoding when the source codec is playable.
// Codec names must match ffprobe codec_name; go-vod compares them verbatim.
const VIDEO_CODEC_PROBES: { codec: string; contentType: string }[] = [
  { codec: 'h264', contentType: 'video/mp4; codecs="avc1.42E01E"' },
  { codec: 'hevc', contentType: 'video/mp4; codecs="hvc1.1.6.L93.B0"' },
  { codec: 'vp8', contentType: 'video/webm; codecs="vp8"' },
  { codec: 'vp9', contentType: 'video/webm; codecs="vp09.00.10.08"' },
  { codec: 'av1', contentType: 'video/mp4; codecs="av01.0.05M.08"' },
];

const PROBE_VIDEO_CONFIG = {
  width: 1920,
  height: 1080,
  bitrate: 5_000_000,
  framerate: 30,
};

let cached: Promise<string[]> | null = null;

/**
 * Playable video codecs in this browser. Never throws;
 * falls back to h264 when probing is unavailable or fails.
 */
export function getPlayableVideoCodecs(): Promise<string[]> {
  if (!cached) {
    cached = detectPlayableVideoCodecs().catch(() => ['h264']);
  }
  return cached;
}

async function detectPlayableVideoCodecs(): Promise<string[]> {
  if (!('mediaCapabilities' in navigator) || !navigator.mediaCapabilities?.decodingInfo) {
    return ['h264'];
  }

  const results = await Promise.all(
    VIDEO_CODEC_PROBES.map(async ({ codec, contentType }) => {
      try {
        const { supported } = await navigator.mediaCapabilities.decodingInfo({
          type: 'file',
          video: {
            contentType,
            ...PROBE_VIDEO_CONFIG,
          },
        });
        return supported ? codec : null;
      } catch {
        return null;
      }
    }),
  );

  const detected = results.filter((c): c is string => c !== null);
  return detected.length ? detected : ['h264'];
}
