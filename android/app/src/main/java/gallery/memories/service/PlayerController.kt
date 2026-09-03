package gallery.memories.service

import android.content.Context
import android.net.Uri
import android.webkit.CookieManager
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.DefaultHttpDataSource
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.hls.HlsMediaSource
import androidx.media3.exoplayer.source.ProgressiveMediaSource
import androidx.media3.ui.PlayerView

/**
 * Extracted ExoPlayer controller usable by both MainActivity and ViewerActivity
 */
@UnstableApi
class PlayerController(private val context: Context) {
    private var player: ExoPlayer? = null
    private var mediaItemIndex = 0
    private var playbackPosition = 0L

    /**
     * Build and bind ExoPlayer to a PlayerView with multiple URIs
     * Handles both local content:// URIs and remote HTTP(S) URIs
     * Falls through on error to next source URI
     */
    fun buildAndBind(
        playerView: PlayerView,
        uris: Array<Uri>,
        isTrustingAllCertificates: Boolean = false,
        loop: Boolean = false
    ) {
        release()

        // Set insecure TLS if enabled
        if (isTrustingAllCertificates) {
            setDefaultInsecureTLS()
        }

        // Build ExoPlayer
        player = ExoPlayer.Builder(context)
            .build()
            .also { exoPlayer ->
                // Bind to player view
                playerView.player = exoPlayer
                playerView.setShowNextButton(false)
                playerView.setShowPreviousButton(false)

                for (uri in uris) {
                    // Create media item from URI
                    val mediaItem = MediaItem.fromUri(uri)

                    // Check if remote or local URI
                    if (uri.toString().contains("http")) {
                        // Add cookies from any existing cookies
                        val cookies = try {
                            CookieManager.getInstance().getCookie(uri.toString())
                        } catch (e: Exception) {
                            null
                        }

                        val httpDataSourceFactory = DefaultHttpDataSource.Factory()
                            .apply {
                                if (cookies != null) {
                                    setDefaultRequestProperties(mapOf("cookie" to cookies))
                                }
                            }
                            .setAllowCrossProtocolRedirects(true)
                        val dataSourceFactory = DefaultDataSource.Factory(context, httpDataSourceFactory)

                        // Check if HLS source from URI (contains .m3u8 anywhere)
                        exoPlayer.addMediaSource(
                            if (uri.toString().contains(".m3u8")) {
                                HlsMediaSource.Factory(dataSourceFactory)
                                    .createMediaSource(mediaItem)
                            } else {
                                ProgressiveMediaSource.Factory(dataSourceFactory)
                                    .createMediaSource(mediaItem)
                            }
                        )
                    } else {
                        exoPlayer.setMediaItems(listOf(mediaItem), mediaItemIndex, playbackPosition)
                    }
                }

                // Catch errors and fall back to other sources
                exoPlayer.addListener(object : Player.Listener {
                    override fun onPlayerError(error: PlaybackException) {
                        exoPlayer.seekToNext()
                        exoPlayer.playWhenReady = true
                        exoPlayer.play()
                    }
                })

                exoPlayer.repeatMode = if (loop) Player.REPEAT_MODE_ONE else Player.REPEAT_MODE_OFF

                // Prepare the player
                exoPlayer.prepare()
                exoPlayer.playWhenReady = true
            }
    }

    /**
     * Pause and release the player
     */
    fun pause() {
        player?.let { exoPlayer ->
            playbackPosition = exoPlayer.currentPosition
            mediaItemIndex = exoPlayer.currentMediaItemIndex
            exoPlayer.pause()
            release()
        }
    }

    /**
     * Stop and release the player
     */
    fun stop() {
        player?.let { exoPlayer ->
            exoPlayer.stop()
        }
        release()
    }

    /**
     * Resume playback from saved position
     */
    fun resume() {
        player?.playWhenReady = true
    }

    /**
     * Release the player
     */
    fun release() {
        player?.release()
        player = null
    }

    /**
     * Get the current player instance
     */
    fun getPlayer(): ExoPlayer? = player

    private fun setDefaultInsecureTLS() {
        try {
            val trustAllCerts = arrayOf<javax.net.ssl.TrustManager>(
                object : javax.net.ssl.X509TrustManager {
                    override fun getAcceptedIssuers(): Array<java.security.cert.X509Certificate>? = null
                    override fun checkClientTrusted(
                        certs: Array<java.security.cert.X509Certificate>,
                        authType: String
                    ) {}

                    override fun checkServerTrusted(
                        certs: Array<java.security.cert.X509Certificate>,
                        authType: String
                    ) {}
                }
            )

            val sslContext = javax.net.ssl.SSLContext.getInstance("SSL")
            sslContext.init(null, trustAllCerts, java.security.SecureRandom())

            javax.net.ssl.HttpsURLConnection.setDefaultSSLSocketFactory(sslContext.socketFactory)
            javax.net.ssl.HttpsURLConnection.setDefaultHostnameVerifier { _, _ -> true }
        } catch (e: Exception) {
            e.printStackTrace()
        }
    }
}
