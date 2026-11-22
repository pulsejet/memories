/** Custom message channel between the viewer (sender) and the TV (receiver) */
export const CAST_NAMESPACE = 'urn:x-cast:gallery.memories.cast';

/**
 * Sent as `v` with every message.
 * A receiver may serve senders of other Memories versions (e.g. if hosted centrally),
 * so changes must stay backwards compatible: only add optional fields or new message
 * types, and ignore unknown ones. Bump this when adding to the protocol.
 */
export const CAST_PROTOCOL_VERSION = 1;

export type Versioned<T> = T & { v: number };

export type CastSenderMessage = {
  type: 'show';
  /** Unique key of the item, echoed back in events */
  key: string;
  url: string;
  video: boolean;
  date?: string;
  place?: string;
  /** URLs of images likely to be shown next */
  preload: string[];
};

export type CastReceiverMessage = { type: 'ended'; key: string };
