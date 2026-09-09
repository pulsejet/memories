package gallery.memories

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
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
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
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
import gallery.memories.service.AssetService
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

    var isTransparentBars = false
        private set

    private val memoriesRegex = Regex("/apps/memories/.*$")
    private var host: String? = null

    private var chooseFileCallback: ValueCallback<Array<Uri>>? = null
    private lateinit var chooseFileIntentLauncher: ActivityResultLauncher<Intent>
    private var mClearHistoryOnLoad = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        setContentView(binding.root)

        // Enable insets for Android 15 or newer
        if (SDK_INT >= 35) {
            // Apply the insets on the inner coordinator, which is not the root element.
            // This way we can still set the background of the root and make sure the style
            // is visible under the status and navigation bars.
            binding.coordinator.setOnApplyWindowInsetsListener { v, windowInsets ->
                if (isTransparentBars) {
                    // Edge-to-edge pages (welcome / waiting): draw under system bars.
                    v.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                        leftMargin = 0
                        rightMargin = 0
                        topMargin = 0
                        bottomMargin = 0
                    }
                } else {
                    val insets = windowInsets.getInsets(WindowInsets.Type.systemBars())
                    // Apply the insets as a margin to the view.
                    v.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                        leftMargin = insets.left
                        rightMargin = insets.right
                        topMargin = insets.top
                        bottomMargin = insets.bottom
                    }
                }

                // Don't want the window insets to keep passing down to descendant views.
                WindowInsets.CONSUMED
            }
        }

        // Handle back gesture on devices with Android 16 or newer
        if (SDK_INT >= 36) {
            onBackInvokedDispatcher.registerOnBackInvokedCallback(
                OnBackInvokedDispatcher.PRIORITY_DEFAULT
            ) {
                onGoBack()
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
        if (SDK_INT < 36 && event.action == KeyEvent.ACTION_DOWN) {
            when (keyCode) {
                KeyEvent.KEYCODE_BACK -> {
                    onGoBack()
                    return true
                }
            }
        }

        return super.onKeyDown(keyCode, event)
    }

    private fun onGoBack() {
        if (binding.webview.canGoBack()) {
            binding.webview.goBack()
        } else {
            finish()
        }
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
                // Everything on the localhost proxy is internal
                if (request.url.host == "127.0.0.1") {
                    return false
                }

                val pathMatches = request.url.path?.matches(memoriesRegex) == true
                val hostMatches = request.url.host.equals(host)
                if (pathMatches && hostMatches) {
                    return false
                }

                // Open external links in browser
                try {
                    Intent(Intent.ACTION_VIEW, request.url).apply { startActivity(this) }
                } catch (e: ActivityNotFoundException) {
                    Toast.makeText(view.context, "No app found to open this link", Toast.LENGTH_SHORT).show()
                }

                return true
            }

            override fun onPageFinished(view: WebView, url: String) {
                super.onPageFinished(view, url)
                if (mClearHistoryOnLoad) {
                    mClearHistoryOnLoad = false
                    view.clearHistory()
                }
            }

            override fun onReceivedError(
                view: WebView,
                request: WebResourceRequest,
                error: WebResourceError
            ) {
                if (request.isForMainFrame) {
                    Log.w(
                        TAG,
                        "Page failed: ${request.url} " +
                            "code=${error.errorCode} ${error.description}"
                    )
                    Toast.makeText(
                        view.context,
                        "Failed to load page: ${error.description}",
                        Toast.LENGTH_LONG
                    ).show()
                }
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

        // Start the localhost server first: every page (welcome included)
        // is served through it now.
        try {
            nativex.local.ensureStarted()
        } catch (e: Exception) {
            Log.w(TAG, "Local server failed to start: ${e.message}")
            Toast.makeText(this, "Local server failed to start", Toast.LENGTH_LONG).show()
            return
        }

        // Hand the per-process server secret to the WebView (and ExoPlayer,
        // which copies WebView cookies into its requests). Without this
        // cookie the localhost server rejects everything, so other
        // on-device apps cannot use it. The set is async: only load once
        // it completes, otherwise the first requests go out cookieless
        // and get rejected.
        CookieManager.getInstance().setCookie(
            "http://127.0.0.1/",
            "local-auth=${nativex.local.secret}; Path=/"
        ) {
            runOnUiThread { onLocalCookieReady() }
        }
        CookieManager.getInstance().flush()
    }

    private fun onLocalCookieReady() {
        val isApp = loadDefaultUrl()

        // Start version check if loaded account
        if (isApp) {
            // Do not use the threadPool here since this might block indefinitely
            Thread { nativex.account.checkCredentialsAndVersion() }.start()
        }
    }

    fun loadDefaultUrl(): Boolean {
        // Logged in: app first, reconcile assets in the background
        if (nativex.http.baseUrl() != null) {
            startLocalApp()
            return true
        }

        // Not logged in: drop any stale proxy session and show welcome
        nativex.local.resetSession()
        setTransparentBars(true, true)
        binding.webview.loadUrl(localStaticUrl("welcome.html"))
        return false
    }

    /** Load the snapshot on disk now; update in the background if stale. */
    fun startLocalApp() {
        val base = nativex.http.baseUrl() ?: return
        if (nativex.assets.hasSnapshot(base) && loadLocalApp()) {
            Thread { backgroundUpdateCheck() }.start()
        } else {
            showWaitingAndEnsure()
        }
    }

    private fun backgroundUpdateCheck() {
        try {
            val fresh = nativex.assets.fetchDescribe() ?: return
            if (nativex.assets.isCurrent(fresh)) return
            Log.i(TAG, "Assets changed, updating in foreground")
        runOnUiThread {
            setTransparentBars(true, true)
            binding.webview.loadUrl(localStaticUrl("waiting.html"))
        }
        if (!nativex.assets.syncAndAwait(fresh, 15 * 60 * 1000)) {
            runOnUiThread { nativex.toast("Update failed", true) }
            return
        }
        runOnUiThread { loadLocalApp() }
        } catch (e: Exception) {
            Log.w(TAG, "Update check failed", e)
            runOnUiThread { nativex.toast("Update check failed", true) }
        }
    }

    /**
     * Show progress, ensure a current asset snapshot (downloading first
     * if needed), then load the local shell. Only used when there is
     * nothing on disk yet; otherwise the app loads first and updates
     * in the background.
     */
    private fun showWaitingAndEnsure() {
        host = "127.0.0.1"
        setTransparentBars(true, true)
        binding.webview.loadUrl(localStaticUrl("waiting.html"))
        Thread {
            try {
                val ok = nativex.assets.ensureFreshAndWait()
                Log.i(TAG, "Offline ensure done: $ok")
                runOnUiThread {
                    if (ok) {
                        if (!loadLocalApp()) {
                            Log.w(TAG, "Offline ensure ok but load failed")
                            nativex.toast("Could not load offline files", true)
                        }
                    } else {
                        nativex.toast("Could not load offline files", true)
                    }
                }
            } catch (e: Exception) {
                Log.w(TAG, "Offline ensure failed", e)
                runOnUiThread { nativex.toast("Could not load offline files", true) }
            }
        }.start()
    }

    /** URL of a bundled entry page, served through the localhost server. */
    fun localStaticUrl(name: String): String {
        return nativex.local.origin() + "/local/static/$name"
    }

    /**
     * Load the locally served app from the current snapshot.
     * Session and CSRF heal inline on first use.
     */
    fun loadLocalApp(): Boolean {
        val base = nativex.http.baseUrl() ?: return false
        val (_, dir) = nativex.assets.current ?: nativex.assets.latestSnapshot(base) ?: return false
        val describe = nativex.assets.readDescribe(dir) ?: return false

        val webRoot = AssetService.webrootOf(base)
        nativex.local.configure(AssetService.originOf(base), webRoot, dir, base)

        // Session and token heal inline on first use; load straight away.
        host = "127.0.0.1"
        binding.webview.clearHistory()
        mClearHistoryOnLoad = true
        binding.webview.loadUrl(nativex.local.origin() + "$webRoot/")
        return true
    }

    fun initializePlayer(uris: Array<Uri>, uid: Long, loop: Boolean = false) {
        if (player != null) {
            if (playerUid == uid) return
            player?.release()
            player = null

            // New video: forget the previous video's state so this one autoplays from the start
            playWhenReady = true
            mediaItemIndex = 0
            playbackPosition = 0L
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
                        val httpDataSourceFactory =
                            DefaultHttpDataSource.Factory()
                                .setAllowCrossProtocolRedirects(true)
                        CookieManager.getInstance().getCookie(uri.toString())?.let { cookies ->
                            httpDataSourceFactory.setDefaultRequestProperties(mapOf("cookie" to cookies))
                        }
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
                        exoPlayer.addMediaItem(mediaItem)
                    }
                }

                // Restore saved position
                if (exoPlayer.mediaItemCount > 0) {
                    exoPlayer.seekTo(
                        mediaItemIndex.coerceAtMost(exoPlayer.mediaItemCount - 1),
                        playbackPosition
                    )
                }

                // Catch errors and fall back to other sources
                var playerErrorCount = 0
                exoPlayer.addListener(object : Player.Listener {
                    override fun onPlayerError(error: PlaybackException) {
                        Log.w(TAG, "Player error, skipping source", error)
                        playerErrorCount++
                        if (exoPlayer.hasNextMediaItem() && playerErrorCount < exoPlayer.mediaItemCount) {
                            exoPlayer.seekToNext()
                            exoPlayer.playWhenReady = true
                            exoPlayer.play()
                        } else {
                            destroyPlayer(playerUid ?: return)
                        }
                    }
                })


                exoPlayer.repeatMode = if (loop) Player.REPEAT_MODE_ONE else Player.REPEAT_MODE_OFF

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
        isTransparentBars = false

        if (SDK_INT < 35) {
            androidx.core.view.WindowCompat.setDecorFitsSystemWindows(window, true)
        }
        if (SDK_INT >= 29) {
            window.isStatusBarContrastEnforced = true
            window.isNavigationBarContrastEnforced = true
        }

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
            window.navigationBarColor = parsed
            window.statusBarColor = parsed
        } catch (_: Exception) {
            Log.w(TAG, "Invalid color: $color")
            return
        }

        if (SDK_INT >= 35) {
            binding.coordinator.requestApplyInsets()
        }
    }

    /**
     * Make status and navigation bars transparent so edge-to-edge
     * pages (welcome / waiting) draw underneath them.
     * The page background is dark, so light icons are used by default.
     */
    fun setTransparentBars(transparent: Boolean, isDark: Boolean = true) {
        isTransparentBars = transparent
        if (!transparent) {
            restoreTheme()
            return
        }

        androidx.core.view.WindowCompat.setDecorFitsSystemWindows(window, false)
        if (SDK_INT >= 29) {
            window.isStatusBarContrastEnforced = false
            window.isNavigationBarContrastEnforced = false
        }

        val appearance =
            WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS
        window.insetsController?.setSystemBarsAppearance(
            if (isDark) 0 else appearance,
            appearance
        )

        try {
            window.statusBarColor = Color.TRANSPARENT
            window.navigationBarColor = Color.TRANSPARENT
        } catch (_: Exception) {
        }
        try {
            // Matches the top of the welcome / waiting gradient.
            binding.root.setBackgroundColor(Color.parseColor("#0d2038"))
        } catch (_: Exception) {
        }
        try {
            binding.coordinator.updateLayoutParams<ViewGroup.MarginLayoutParams> {
                leftMargin = 0
                rightMargin = 0
                topMargin = 0
                bottomMargin = 0
            }
        } catch (_: Exception) {
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