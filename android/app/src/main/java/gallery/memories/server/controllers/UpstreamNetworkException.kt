package gallery.memories.server.controllers

import java.io.IOException

/**
 * Upstream is unreachable (offline, DNS, connect/timeout, reset).
 * The local server must drop the connection without an HTTP response
 * so the WebView sees a real network failure (axios ERR_NETWORK)
 * instead of a synthetic HTTP 5xx.
 */
class UpstreamNetworkException(cause: IOException) : IOException("upstream unreachable", cause)
