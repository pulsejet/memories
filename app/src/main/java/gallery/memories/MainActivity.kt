package gallery.memories

import android.annotation.SuppressLint
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.KeyEvent
import android.view.View
import android.view.WindowInsetsController
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
    val binding by lazy(LazyThreadSafetyMode.NONE) {
        ActivityMainBinding.inflate(layoutInflater)
    }

    private lateinit var mNativeX: NativeX

    private var player: ExoPlayer? = null
    private var playerUri: Uri? = null
    private var playerUid: String? = null
    private var playWhenReady = true
    private var mediaItemIndex = 0
    private var playbackPosition = 0L

    private val memoriesRegex = Regex("/apps/memories/.*$")

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(binding.root)

        // Restore last known look
        restoreTheme()

        // Initialize services
        mNativeX = NativeX(this)

        // Load JavaScript
        initializeWebView()

        // Destroy video after 1 seconds (workaround for video not showing on first load)
        binding.videoView.postDelayed({
            binding.videoView.alpha = 1.0f
            binding.videoView.visibility = View.GONE
        }, 1000)
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

    override fun onKeyDown(keyCode: Int, event: KeyEvent): Boolean {
        if (event.getAction() == KeyEvent.ACTION_DOWN) {
            when (keyCode) {
                KeyEvent.KEYCODE_BACK -> {
                    if (binding.webview.canGoBack()) {
                        binding.webview.goBack()
                    } else {
                        finish()
                    }
                    return true
                }
            }
        }
        return super.onKeyDown(keyCode, event)
    }

    @SuppressLint("SetJavaScriptEnabled", "ClickableViewAccessibility")
    private fun initializeWebView() {
        // Intercept local APIs
        binding.webview.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                // TODO: check host as well
                if (request.url.path?.matches(memoriesRegex) == true) {
                    return false
                }

                // Open external links in browser
                Intent(Intent.ACTION_VIEW, request.url).apply { startActivity(this) }

                return true
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
//        binding.webview.loadUrl("http://10.0.2.2:8035/index.php/apps/memories/")
        binding.webview.loadUrl("https://uncanny-subdue.loca.lt/index.php/apps/memories/")
        binding.webview.setBackgroundColor(Color.TRANSPARENT)
        WebView.setWebContentsDebuggingEnabled(true);
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
                // Bind to player view
                binding.videoView.player = exoPlayer
                binding.videoView.visibility = View.VISIBLE
                binding.videoView.setShowNextButton(false)
                binding.videoView.setShowPreviousButton(false)

                // Check if HLS source from URI (contains .m3u8 anywhere)
                if (uri.toString().contains(".m3u8")) {
                    exoPlayer.addMediaSource(HlsMediaSource.Factory(dataSourceFactory)
                            .createMediaSource(mediaItem))
                } else {
                    exoPlayer.setMediaItems(listOf(mediaItem), mediaItemIndex, playbackPosition)
                }

                // Start the player
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

    fun storeTheme(color: String?, isDark: Boolean) {
        if (color == null) return
        getSharedPreferences(getString(R.string.preferences_key), 0).edit()
            .putString("themeColor", color)
            .putBoolean("themeDark", isDark)
            .apply()
    }

    fun restoreTheme() {
        val preferences = getSharedPreferences(getString(R.string.preferences_key), 0)
        val color = preferences.getString("themeColor", null)
        val isDark = preferences.getBoolean("themeDark", false)
        applyTheme(color, isDark)
    }

    fun applyTheme(color: String?, isDark: Boolean) {
        if (color == null) return
        setTheme(if (isDark) android.R.style.Theme_Black else android.R.style.Theme_Light)
        window.navigationBarColor = Color.parseColor(color)
        window.statusBarColor = Color.parseColor(color)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            val appearance = WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS
            window.insetsController?.setSystemBarsAppearance(if (isDark) 0 else appearance, appearance)
        } else {
            window.decorView.systemUiVisibility = if (isDark) 0 else View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR or View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR
        }
    }
}