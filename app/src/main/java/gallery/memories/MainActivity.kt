package gallery.memories

import android.annotation.SuppressLint
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.webkit.*
import androidx.appcompat.app.AppCompatActivity
import androidx.media3.common.MediaItem
import androidx.media3.common.util.UnstableApi
import androidx.media3.common.util.Util
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.DefaultHttpDataSource
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.hls.HlsMediaSource
import gallery.memories.databinding.ActivityMainBinding

@UnstableApi class MainActivity : AppCompatActivity() {
    private val binding by lazy(LazyThreadSafetyMode.NONE) {
        ActivityMainBinding.inflate(layoutInflater)
    }

    private lateinit var mNativeX: NativeX

    private var player: ExoPlayer? = null
    private var playerUri: Uri? = null
    private var playerUid: String? = null
    private var playWhenReady = true
    private var mediaItemIndex = 0
    private var playbackPosition = 0L

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(binding.root)

        // Initialize services
        mNativeX = NativeX(this)

        // Load JavaScript
        initializeWebView()
    }

    override fun onDestroy() {
        super.onDestroy()
        mNativeX.destroy()
    }

    public override fun onResume() {
        super.onResume()
        if (playerUri != null && (Util.SDK_INT <= 23 || player == null)) {
            initializePlayer(playerUri!!, playerUid!!)
        }
    }

    public override fun onPause() {
        super.onPause()
        if (Util.SDK_INT <= 23) {
            releasePlayer()
        }
    }

    public override fun onStop() {
        super.onStop()
        if (Util.SDK_INT > 23) {
            releasePlayer()
        }
    }

    @SuppressLint("SetJavaScriptEnabled", "ClickableViewAccessibility")
    private fun initializeWebView() {
        // Intercept local APIs
        binding.webview.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                view.loadUrl(request.url.toString())
                return false
            }

            override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest): WebResourceResponse? {
                return if (request.url.host == "127.0.0.1") {
                    mNativeX.handleRequest(request)
                } else null
            }
        }

        // Pass through touch events
        binding.webview.setOnTouchListener { _, event ->
            if (player != null) {
                binding.videoView.dispatchTouchEvent(event)
            }
            false
        }

        val webSettings = binding.webview.settings
        webSettings.javaScriptEnabled = true
        webSettings.javaScriptCanOpenWindowsAutomatically = true
        webSettings.allowContentAccess = true
        webSettings.domStorageEnabled = true
        webSettings.databaseEnabled = true
        webSettings.userAgentString = "memories-native-android/0.0"
        binding.webview.clearCache(true)
        binding.webview.addJavascriptInterface(mNativeX, "nativex")
        binding.webview.loadUrl("http://10.0.2.2:8035/index.php/apps/memories/")
        binding.webview.setBackgroundColor(0x00000000)
    }

    fun initializePlayer(uri: Uri, uid: String) {
        if (player != null) {
            if (playerUid.equals(uid)) return
            player?.release()
            player = null
        }

        // Prevent re-creating
        playerUri = uri
        playerUid = uid

        // Add cookies from webview to data source
        val cookies = CookieManager.getInstance().getCookie(uri.toString())
        val httpDataSourceFactory =
            DefaultHttpDataSource.Factory()
                .setDefaultRequestProperties(mapOf("cookie" to cookies))
                .setAllowCrossProtocolRedirects(true)
        val dataSourceFactory = DefaultDataSource.Factory(this, httpDataSourceFactory)

        // Create media item from local or remote uri
        val mediaItem = MediaItem.fromUri(uri)

        // Build exoplayer
        player = ExoPlayer.Builder(this)
            .build()
            .also { exoPlayer ->
                binding.videoView.player = exoPlayer
                binding.videoView.visibility = View.VISIBLE

                val hlsMediaSource = HlsMediaSource.Factory(dataSourceFactory)
                    .createMediaSource(mediaItem);
                exoPlayer.addMediaSource(hlsMediaSource)

//                val mediaItem = MediaItem.fromUri(uri)
//                exoPlayer.setMediaItems(listOf(mediaItem), mediaItemIndex, playbackPosition)
                exoPlayer.playWhenReady = playWhenReady
                exoPlayer.prepare()
            }
    }

    fun destroyPlayer(uid: String) {
        if (playerUid.equals(uid)) {
            releasePlayer()

            // Reset vars
            playWhenReady = true
            mediaItemIndex = 0
            playbackPosition = 0L
            playerUri = null
            playerUid = null
        }
    }

    private fun releasePlayer() {
        player?.let { exoPlayer ->
            playbackPosition = exoPlayer.currentPosition
            mediaItemIndex = exoPlayer.currentMediaItemIndex
            playWhenReady = exoPlayer.playWhenReady
            exoPlayer.release()
        }
        player = null
        binding.videoView.visibility = View.GONE
    }
}