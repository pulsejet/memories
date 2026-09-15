package gallery.memories.server.controllers

import java.io.IOException

/**
 * Upstream is unreachable (offline, DNS, connect/timeout, reset).
 * The local server answers 504 Gateway Timeout so the frontend can tell
 * "upstream offline" apart from a synthetic HTTP 5xx; see isNetworkError().
 */
class UpstreamNetworkException(cause: IOException) : IOException("upstream unreachable", cause)
