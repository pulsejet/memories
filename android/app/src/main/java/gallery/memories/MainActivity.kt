package gallery.memories

import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.res.Configuration
import android.graphics.Color
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.Uri
import android.net.http.SslError
import android.os.Build.VERSION.SDK_INT
import android.os.Bundle
import android.util.Base64
import android.util.Log
import android.view.KeyEvent
import android.view.View
import android.view.ViewGroup
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.ServiceWorkerClient
import android.webkit.ServiceWorkerController
import android.webkit.SslErrorHandler
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
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

    private var mOfflinePageShowing = false
    private var mLoadPending = false
    private var mReloadOnceOnLoad = false

    private val mNetworkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            // Reload the app if we failed to load it due to being offline
            if (mOfflinePageShowing) {
                runOnUiThread { loadDefaultUrl() }
            }
        }

        override fun onCapabilitiesChanged(network: Network, caps: NetworkCapabilities) {
            // onAvailable may fire before the network is actually usable
            // (e.g. DNS through a VPN that is still reconnecting), so also
            // reload when the network becomes validated
            if (mOfflinePageShowing && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)) {
                runOnUiThread { loadDefaultUrl() }
            }
        }
    }

    private val memoriesRegex = Regex("/apps/memories/.*$")
    private var host: String? = null

    private var chooseFileCallback: ValueCallback<Array<Uri>>? = null
    private lateinit var chooseFileIntentLauncher: ActivityResultLauncher<Intent>

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        setContentView(binding.root)

        // Enable insets for Android 15 or newer
        if (SDK_INT >= 35) {
            // Apply the insets on the inner coordinator, which is not the root element.
            // This way we can still set the background of the root and make sure the style
            // is visible under the status and navigation bars.
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

        // Reload the app automatically when connectivity returns
        getSystemService(ConnectivityManager::class.java)
            ?.registerDefaultNetworkCallback(mNetworkCallback)

        // Destroy video after 1 seconds (workaround for video not showing on first load)
        binding.videoView.postDelayed({
            binding.videoView.alpha = 1.0f
            binding.videoView.visibility = View.GONE
        }, 1000)
    }

    override fun onDestroy() {
        super.onDestroy()
        getSystemService(ConnectivityManager::class.java)
            ?.unregisterNetworkCallback(mNetworkCallback)
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

            override fun shouldInterceptRequest(
                view: WebView,
                request: WebResourceRequest
            ): WebResourceResponse? {
                return if (request.url.host == "127.0.0.1") {
                    nativex.handleRequest(request)
                } else null
            }

            override fun onPageFinished(view: WebView, url: String?) {
                mLoadPending = false

                // A page that commits in a fresh renderer process on the
                // offline path can end up never painted even though it loaded
                // correctly (observed with the Vanadium WebView). An in-page
                // reload runs in the same process and reliably repaints.
                if (mReloadOnceOnLoad) {
                    mReloadOnceOnLoad = false
                    if (url?.startsWith("http") == true) {
                        view.evaluateJavascript("location.reload()", null)
                    }
                }
            }

            override fun onReceivedError(
                view: WebView,
                request: WebResourceRequest,
                error: WebResourceError
            ) {
                // Show the offline page if the app itself failed to load, e.g. the
                // device is offline and the service worker did not serve a cached copy
                if (request.isForMainFrame) {
                    Log.w(TAG, "onReceivedError: ${error.errorCode} ${error.description}")
                    mLoadPending = false
                    showOfflinePage()
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

        // Requests from pages controlled by a service worker bypass the
        // WebViewClient above, so the local API interception must also be
        // registered on the service worker controller
        ServiceWorkerController.getInstance().setServiceWorkerClient(
            object : ServiceWorkerClient() {
                override fun shouldInterceptRequest(request: WebResourceRequest): WebResourceResponse? {
                    return if (request.url.host == "127.0.0.1") {
                        nativex.handleRequest(request)
                    } else null
                }
            })

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
        // The offline page and the network callbacks may all ask for a
        // reload around the same time; re-navigating while the previous
        // load is still in flight would abort it
        if (mLoadPending) return false
        mLoadPending = true

        // Failsafe: never leave the pending flag stuck if the load never
        // finishes nor errors out
        binding.webview.postDelayed({ mLoadPending = false }, 30000)

        // Coming back from the offline page needs a surface nudge (see
        // onPageFinished)
        if (mOfflinePageShowing) mReloadOnceOnLoad = true

        mOfflinePageShowing = false

        // Load app interface if authenticated
        host = nativex.http.loadWebView(binding.webview)
        if (host != null) return true

        // Load welcome page
        binding.webview.loadUrl("file:///android_asset/welcome.html")
        return false
    }

    /**
     * Show the offline fallback page.
     */
    fun showOfflinePage() {
        runOnUiThread {
            // Do not reload the page on repeated errors
            if (mOfflinePageShowing) return@runOnUiThread
            mOfflinePageShowing = true
            mReloadOnceOnLoad = true

            // Serve the page from the app origin instead of file:// —
            // navigating between file:// and the app URL swaps renderer
            // processes, which can leave the WebView blank after reload
            val base = nativex.http.baseUrl
            if (base != null) {
                binding.webview.loadDataWithBaseURL(
                    base, readAssetInlined("offline.html"), "text/html", "UTF-8", null
                )
            } else {
                binding.webview.loadUrl("file:///android_asset/offline.html")
            }
        }
    }

    /**
     * Read an asset page and inline its stylesheet and logo, so it can be
     * served from any origin with loadDataWithBaseURL.
     */
    private fun readAssetInlined(name: String): String {
        fun read(file: String) = assets.open(file).bufferedReader().use { it.readText() }
        val css = read("styles.css")
        val logo = Base64.encodeToString(read("memories.svg").toByteArray(), Base64.NO_WRAP)
        return read(name)
            .replace("""<link rel="stylesheet" href="styles.css" />""", "<style>$css</style>")
            .replace("memories.svg", "data:image/svg+xml;base64,$logo")
    }

    fun initializePlayer(uris: Array<Uri>, uid: Long, loop: Boolean = false) {
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