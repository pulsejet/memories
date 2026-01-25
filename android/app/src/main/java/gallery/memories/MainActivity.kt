package gallery.memories

import android.annotation.SuppressLint
import android.content.Intent
import android.content.res.Configuration
import android.graphics.Color
import android.net.Uri
import android.net.http.SslError
import android.os.Build.VERSION.SDK_INT
import android.os.Bundle
import android.util.Log
import android.view.KeyEvent
import android.view.View
import android.view.ViewGroup
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.SslErrorHandler
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import android.window.OnBackInvokedDispatcher
import androidx.activity.result.ActivityResult
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.edit
import androidx.core.graphics.toColorInt
import androidx.core.view.updateLayoutParams
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
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors


@UnstableApi
class MainActivity : AppCompatActivity() {
    companion object {
        val TAG: String = MainActivity::class.java.simpleName
    }

    val binding by lazy(LazyThreadSafetyMode.NONE) {
        ActivityMainBinding.inflate(layoutInflater)
    }

    val threadPool: ExecutorService = Executors.newFixedThreadPool(4)

    private lateinit var nativex: NativeX

    private var player: ExoPlayer? = null
    private var playerUris: Array<Uri>? = null
    private var playerUid: Long? = null
    private var playWhenReady = true
    private var mediaItemIndex = 0
    private var playbackPosition = 0L

    private var mNeedRefresh = false

    private val memoriesRegex = Regex("/apps/memories/.*$")
    private var host: String? = null

    private var chooseFileCallback: ValueCallback<Array<Uri>>? = null
    private lateinit var chooseFileIntentLauncher: ActivityResultLauncher<Intent>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        setContentView(binding.root)

        // Enable insets for Android 16 or newer
        if (SDK_INT >= 36) {
            binding.coordinator.setOnApplyWindowInsetsListener { v, windowInsets ->
                val insets = windowInsets.getInsets(WindowInsets.Type.systemBars())
                // Apply the insets as a margin to the view.
                v.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                    leftMargin = insets.left
                    rightMargin = insets.right
                    topMargin = insets.top
                    bottomMargin = insets.bottom
                }

                // Don't want the window insets to keep passing down to descendant views.
                WindowInsets.CONSUMED
            }
        }

        // Handle back gesture on devices with Android 13 or newer
        if (SDK_INT >= 33) {
            onBackInvokedDispatcher.registerOnBackInvokedCallback(
                OnBackInvokedDispatcher.PRIORITY_DEFAULT
            ) {
                if (binding.webview.canGoBack()) {
                    binding.webview.goBack()
                } else {
                    finish()
                }
            }
        }

        // Set fullscreen mode if in landscape
        val orientation = resources.configuration.orientation
        setFullscreen(orientation == Configuration.ORIENTATION_LANDSCAPE)

        // Restore last known look
        restoreTheme()

        // Initialize services
        nativex = NativeX(this)

        // Sync if permission is available
        nativex.doMediaSync(false)

        // Initialize handlers
        initializeIntentHandlers()

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
        binding.webview.removeAllViews()
        binding.coordinator.removeAllViews()
        binding.webview.destroy()
        nativex.destroy()
    }

    override fun onConfigurationChanged(config: Configuration) {
        super.onConfigurationChanged(config)

        // Hide the status bar in landscape
        setFullscreen(config.orientation == Configuration.ORIENTATION_LANDSCAPE)
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

    @SuppressLint("GestureBackNavigation")
    override fun onKeyDown(keyCode: Int, event: KeyEvent): Boolean {
        // This is only needed for devices with Android 12 or older
        if (SDK_INT < 33) {
            if (event.action == KeyEvent.ACTION_DOWN) {
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
        }

        return super.onKeyDown(keyCode, event)
    }

    private fun initializeIntentHandlers() {
        // File chooser
        chooseFileIntentLauncher = registerForActivityResult(
            ActivityResultContracts.StartActivityForResult()
        ) { result: ActivityResult ->
            val intent = result.data

            // Attempt to parse URIs from result
            var uris = WebChromeClient.FileChooserParams.parseResult(result.resultCode, intent)

            // Use clipData if nothing found in uris
            if (uris.isNullOrEmpty() && intent?.clipData != null) {
                uris =
                    Array(intent.clipData!!.itemCount) { i -> intent.clipData!!.getItemAt(i).uri }
            }

            chooseFileCallback?.onReceiveValue(uris)
            chooseFileCallback = null
        }
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

            @SuppressLint("WebViewClientOnReceivedSslError")
            override fun onReceivedSslError(
                view: WebView?,
                handler: SslErrorHandler?,
                error: SslError?
            ) {
                if (nativex.http.isTrustingAllCertificates) {
                    handler?.proceed()
                } else {
                    nativex.toast("Failed to load due to SSL error: ${error?.primaryError}", true)
                    super.onReceivedSslError(view, handler, error)
                }
            }
        }

        // Use the web chrome client to handle file uploads
        binding.webview.webChromeClient = object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest) {
                request.grant(request.resources)
            }

            override fun onShowFileChooser(
                vw: WebView,
                filePathCallback: ValueCallback<Array<Uri>>,
                fileChooserParams: FileChooserParams
            ): Boolean {
                chooseFileCallback?.onReceiveValue(null)
                chooseFileCallback = filePathCallback
                val intent = fileChooserParams.createIntent()

                // This is a very ugly hack to prevent the photo picker from opening.
                // The photo picker strips  off the metadata and filename; passing
                // text as a mime opens the original file picker
                intent.putExtra(Intent.EXTRA_MIME_TYPES, arrayOf("image/*", "video/*", "text/*"))

                chooseFileIntentLauncher.launch(intent)
                return true
            }
        }

        // Pass through touch events
        binding.webview.setOnTouchListener { _, event ->
            if (player != null) {
                binding.videoView.dispatchTouchEvent(event)
            }
            false
        }

        // Mark this is the native app in user agent
        val userAgent =
            getString(R.string.ua_app_prefix) + BuildConfig.VERSION_NAME + " " + WebSettings.getDefaultUserAgent(this)

        // Set up webview settings
        val webSettings = binding.webview.settings
        webSettings.javaScriptEnabled = true
        webSettings.javaScriptCanOpenWindowsAutomatically = true
        webSettings.allowContentAccess = true
        webSettings.domStorageEnabled = true
        webSettings.userAgentString = userAgent
        webSettings.setSupportZoom(false)
        webSettings.builtInZoomControls = false
        webSettings.displayZoomControls = false
        binding.webview.addJavascriptInterface(nativex, "nativex")
        binding.webview.setLayerType(View.LAYER_TYPE_HARDWARE, null)
        binding.webview.setBackgroundColor(Color.TRANSPARENT)

        // Enable debugging in debug builds
        if (BuildConfig.DEBUG) {
            Toast.makeText(this, "Debugging enabled", Toast.LENGTH_SHORT).show()
            binding.webview.clearCache(true)
            WebView.setWebContentsDebuggingEnabled(true)
        }

        // Welcome page or actual app
        nativex.account.refreshCredentials()
        val isApp = loadDefaultUrl()

        // Start version check if loaded account
        if (isApp) {
            // Do not use the threadPool here since this might block indefinitely
            Thread { nativex.account.checkCredentialsAndVersion() }.start()
        }
    }

    fun loadDefaultUrl(): Boolean {
        // Load app interface if authenticated
        host = nativex.http.loadWebView(binding.webview)
        if (host != null) return true

        // Load welcome page
        binding.webview.loadUrl("file:///android_asset/welcome.html")
        return false
    }

    fun initializePlayer(uris: Array<Uri>, uid: Long) {
        if (player != null) {
            if (playerUid == uid) return
            player?.release()
            player = null
        }

        // Prevent re-creating
        playerUris = uris
        playerUid = uid

        // Set insecure TLS if enabled
        if (nativex.http.isTrustingAllCertificates) {
            nativex.http.setDefaultInsecureTLS()
        }

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

    fun destroyPlayer(uid: Long) {
        if (playerUid == uid) {
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

    /**
     * Make the app fullscreen.
     */
    private fun setFullscreen(value: Boolean) {
        if (value) {
            window.attributes.layoutInDisplayCutoutMode =
                WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES
            window.insetsController?.apply {
                hide(WindowInsets.Type.statusBars())
                systemBarsBehavior =
                    WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            }
        } else {
            window.attributes.layoutInDisplayCutoutMode =
                WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_DEFAULT
            window.insetsController?.apply {
                show(WindowInsets.Type.statusBars())
            }
        }
    }

    /**
     * Store a given theme for restoreTheme.
     */
    fun storeTheme(color: String?, isDark: Boolean) {
        if (color == null) return
        getSharedPreferences(getString(R.string.preferences_key), 0).edit {
            putString(getString(R.string.preferences_theme_color), color)
                .putBoolean(getString(R.string.preferences_theme_dark), isDark)
        }
    }

    /**
     * Restore the last known theme color.
     */
    fun restoreTheme() {
        val preferences = getSharedPreferences(getString(R.string.preferences_key), 0)
        val color = preferences.getString(getString(R.string.preferences_theme_color), null)
        val isDark = preferences.getBoolean(getString(R.string.preferences_theme_dark), false)
        applyTheme(color, isDark)
    }

    /**
     * Apply a color theme.
     */
    fun applyTheme(color: String?, isDark: Boolean) {
        if (color == null) return

        // Set system bars
        val appearance =
            WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS
        window.insetsController?.setSystemBarsAppearance(
            if (isDark) 0 else appearance,
            appearance
        )

        // Set colors
        try {
            val parsed = color.trim().toColorInt()
            binding.root.setBackgroundColor(parsed)
            if (SDK_INT < 35) {
                window.navigationBarColor = parsed
                window.statusBarColor = parsed
            }
        } catch (_: Exception) {
            Log.w(TAG, "Invalid color: $color")
            return
        }
    }

    /**
     * Do a soft refresh on the open timeline
     */
    fun refreshTimeline(force: Boolean = false) {
        runOnUiThread {
            // Check webview is loaded
            if (binding.webview.url == null) return@runOnUiThread

            // Schedule for resume if not active
            if (lifecycle.currentState.isAtLeast(Lifecycle.State.RESUMED) || force) {
                mNeedRefresh = false
                busEmit("nativex:db:updated")
                busEmit("memories:timeline:soft-refresh")
            } else {
                mNeedRefresh = true
            }
        }
    }

    /**
     * Emit an event to the nextcloud event bus
     */
    fun busEmit(event: String, data: String = "null") {
        runOnUiThread {
            if (binding.webview.url == null) return@runOnUiThread

            binding.webview.evaluateJavascript(
                "window._nc_event_bus?.emit('$event', $data)",
                null
            )
        }
    }
}