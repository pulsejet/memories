package gallery.memories

import android.annotation.SuppressLint
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.util.Log
import android.view.KeyEvent
import android.view.View
import android.view.WindowInsetsController
import android.webkit.CookieManager
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.Lifecycle
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.DefaultHttpDataSource
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.hls.HlsMediaSource
import androidx.media3.exoplayer.source.ProgressiveMediaSource
import gallery.memories.databinding.ActivityMainBinding

@UnstableApi
class MainActivity : AppCompatActivity() {
    companion object {
        val TAG = MainActivity::class.java.simpleName
    }

    val binding by lazy(LazyThreadSafetyMode.NONE) {
        ActivityMainBinding.inflate(layoutInflater)
    }

    private lateinit var nativex: NativeX

    private var player: ExoPlayer? = null
    private var playerUris: Array<Uri>? = null
    private var playerUid: String? = null
    private var playWhenReady = true
    private var mediaItemIndex = 0
    private var playbackPosition = 0L

    private var mNeedRefresh = false

    private val memoriesRegex = Regex("/apps/memories/.*$")
    private var host: String? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(binding.root)

        // Restore last known look
        restoreTheme()

        // Initialize services
        nativex = NativeX(this)

        // Ensure storage permissions
        ensureStoragePermissions()

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
        binding.webview.removeAllViews();
        binding.coordinator.removeAllViews()
        binding.webview.destroy();
        nativex.destroy()
    }

    public override fun onResume() {
        super.onResume()
        if (playerUris != null && player == null) {
            initializePlayer(playerUris!!, playerUid!!)
        }
        if (mNeedRefresh) {
            refreshTimeline(true)
        }
    }

    public override fun onPause() {
        super.onPause()
    }

    public override fun onStop() {
        super.onStop()
        releasePlayer()
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
            override fun shouldOverrideUrlLoading(
                view: WebView,
                request: WebResourceRequest
            ): Boolean {
                val pathMatches = request.url.path?.matches(memoriesRegex) == true
                val hostMatches = request.url.host.equals(host)
                if (pathMatches && hostMatches) {
                    return false
                }

                // Open external links in browser
                Intent(Intent.ACTION_VIEW, request.url).apply { startActivity(this) }

                return true
            }

            override fun shouldInterceptRequest(
                view: WebView,
                request: WebResourceRequest
            ): WebResourceResponse? {
                return if (request.url.host == "127.0.0.1") {
                    nativex.handleRequest(request)
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

        val userAgent =
            getString(R.string.ua_app_prefix) + BuildConfig.VERSION_NAME + " " + getString(R.string.ua_chrome)

        val webSettings = binding.webview.settings
        webSettings.javaScriptEnabled = true
        webSettings.javaScriptCanOpenWindowsAutomatically = true
        webSettings.allowContentAccess = true
        webSettings.domStorageEnabled = true
        webSettings.databaseEnabled = true
        webSettings.userAgentString = userAgent
        webSettings.setSupportZoom(false)
        webSettings.builtInZoomControls = false
        webSettings.displayZoomControls = false
        binding.webview.addJavascriptInterface(nativex, "nativex")
        binding.webview.setLayerType(View.LAYER_TYPE_HARDWARE, null)
        binding.webview.setBackgroundColor(Color.TRANSPARENT)
        binding.webview.clearCache(true)
        WebView.setWebContentsDebuggingEnabled(true);

        // Welcome page or actual app
        nativex.account.refreshAuthHeader()
        val isApp = loadDefaultUrl()

        // Start version check if loaded account
        if (isApp) {
            Thread {
                nativex.account.checkCredentialsAndVersion()
            }.start()
        }
    }

    fun loadDefaultUrl(): Boolean {
        // Load accounts
        val authHeader = nativex.account.authHeader
        val memoriesUrl = nativex.account.memoriesUrl

        // Load app interface if authenticated
        if (authHeader != null && memoriesUrl != null) {
            // Get host name
            host = Uri.parse(memoriesUrl).host

            // Set authorization header
            binding.webview.loadUrl(
                memoriesUrl, mapOf(
                    "Authorization" to authHeader
                )
            )
            return true
        }

        // Load welcome page
        binding.webview.loadUrl("file:///android_asset/welcome.html");
        return false
    }

    fun ensureStoragePermissions() {
        val requestPermissionLauncher = registerForActivityResult(
            ActivityResultContracts.RequestMultiplePermissions()
        ) { permissions ->

            // we need all of these
            val isGranted = permissions.all { it.value }

            // start synchronization if granted
            if (isGranted) {
                val needFullSync = !hasMediaPermission()

                // Run DB operations in separate thread
                Thread {
                    // Full sync if this is the first time permission was granted
                    if (needFullSync) {
                        nativex.query.syncFullDb()
                    }

                    // Run delta sync and register hooks
                    nativex.query.initialize()
                }.start()
            } else {
                Log.w(TAG, "Storage permission not available")
            }

            // Persist that we have it now
            setHasMediaPermission(isGranted)
        }

        // Request media read permission
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            requestPermissionLauncher.launch(
                arrayOf(
                    android.Manifest.permission.READ_MEDIA_IMAGES,
                    android.Manifest.permission.READ_MEDIA_VIDEO,
                )
            )
        } else {
            requestPermissionLauncher.launch(arrayOf(android.Manifest.permission.READ_EXTERNAL_STORAGE))
        }
    }

    fun initializePlayer(uris: Array<Uri>, uid: String) {
        if (player != null) {
            if (playerUid.equals(uid)) return
            player?.release()
            player = null
        }

        // Prevent re-creating
        playerUris = uris
        playerUid = uid

        // Build exoplayer
        player = ExoPlayer.Builder(this)
            .build()
            .also { exoPlayer ->
                // Bind to player view
                binding.videoView.player = exoPlayer
                binding.videoView.visibility = View.VISIBLE
                binding.videoView.setShowNextButton(false)
                binding.videoView.setShowPreviousButton(false)

                for (uri in uris) {
                    // Create media item from URI
                    val mediaItem = MediaItem.fromUri(uri)

                    // Check if remote or local URI
                    if (uri.toString().contains("http")) {
                        // Add cookies from webview to data source
                        val cookies = CookieManager.getInstance().getCookie(uri.toString())
                        val httpDataSourceFactory =
                            DefaultHttpDataSource.Factory()
                                .setDefaultRequestProperties(mapOf("cookie" to cookies))
                                .setAllowCrossProtocolRedirects(true)
                        val dataSourceFactory =
                            DefaultDataSource.Factory(this, httpDataSourceFactory)

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
            playerUris = null
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
            .putString(getString(R.string.preferences_theme_color), color)
            .putBoolean(getString(R.string.preferences_theme_dark), isDark)
            .apply()
    }

    fun restoreTheme() {
        val preferences = getSharedPreferences(getString(R.string.preferences_key), 0)
        val color = preferences.getString(getString(R.string.preferences_theme_color), null)
        val isDark = preferences.getBoolean(getString(R.string.preferences_theme_dark), false)
        applyTheme(color, isDark)
    }

    fun applyTheme(color: String?, isDark: Boolean) {
        if (color == null) return

        // Set dark mode
        setTheme(if (isDark) android.R.style.Theme_Black else android.R.style.Theme_Light)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            val appearance =
                WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS
            window.insetsController?.setSystemBarsAppearance(
                if (isDark) 0 else appearance,
                appearance
            )
        } else {
            window.decorView.systemUiVisibility =
                if (isDark) 0 else View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR or View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR
        }

        // Set colors
        try {
            val parsed = Color.parseColor(color.trim())
            window.navigationBarColor = parsed
            window.statusBarColor = parsed
        } catch (e: Exception) {
            Log.w(TAG, "Invalid color: $color")
            return
        }
    }

    fun hasMediaPermission(): Boolean {
        return getSharedPreferences(getString(R.string.preferences_key), 0)
            .getBoolean(getString(R.string.preferences_has_media_permission), false)
    }

    private fun setHasMediaPermission(v: Boolean) {
        getSharedPreferences(getString(R.string.preferences_key), 0).edit()
            .putBoolean(getString(R.string.preferences_has_media_permission), v)
            .apply()
    }

    fun refreshTimeline(force: Boolean = false) {
        runOnUiThread {
            // Check webview is loaded
            if (binding.webview.url == null) return@runOnUiThread

            // Schedule for resume if not active
            if (lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED) || force) {
                mNeedRefresh = false
                binding.webview.evaluateJavascript(
                    "window._nc_event_bus?.emit('files:file:created')",
                    null
                )
            } else {
                mNeedRefresh = true
            }
        }
    }
}