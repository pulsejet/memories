package gallery.memories

import android.annotation.SuppressLint
import android.content.res.Configuration
import android.net.Uri
import android.os.Build.VERSION.SDK_INT
import android.os.Bundle
import android.util.Log
import android.view.KeyEvent
import android.view.View
import android.webkit.CookieManager
import android.webkit.WebSettings
import android.webkit.WebView
import android.window.OnBackInvokedDispatcher
import androidx.appcompat.app.AppCompatActivity
import androidx.media3.common.util.UnstableApi
import gallery.memories.data.remote.http.TlsTrust
import gallery.memories.databinding.ActivityMainBinding
import gallery.memories.ui.player.VideoPlayerManager
import gallery.memories.ui.startup.AppStartupCoordinator
import gallery.memories.ui.theme.EdgeToEdgeController
import gallery.memories.ui.theme.ThemeManager
import gallery.memories.ui.web.FileChooserHandler
import gallery.memories.ui.web.JsEventBus
import gallery.memories.ui.web.MemoriesWebChromeClient
import gallery.memories.ui.web.MemoriesWebViewClient
import gallery.memories.ui.web.WebViewSetup
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

@UnstableApi
/** Single-activity host: owns the WebView and components, delegates logic to them. */
class MainActivity : AppCompatActivity() {
    companion object {
        val TAG: String = MainActivity::class.java.simpleName
    }

    val binding by lazy(LazyThreadSafetyMode.NONE) {
        ActivityMainBinding.inflate(layoutInflater)
    }

    val threadPool: ExecutorService = Executors.newFixedThreadPool(4)

    lateinit var nativex: NativeX
        private set

    private lateinit var themes: ThemeManager
    private lateinit var edges: EdgeToEdgeController
    private lateinit var eventBus: JsEventBus
    private lateinit var player: VideoPlayerManager
    private lateinit var chooser: FileChooserHandler
    private lateinit var startup: AppStartupCoordinator

    var isTransparentBars = false
    var host: String? = null
    /** One-shot: cleared by the web client after the main page loads so Back skips entry pages. */
    var clearHistoryOnLoad = false

    val memoriesRegex = Regex("/apps/memories/.*$")

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(binding.root)

        eventBus = JsEventBus(this)
        nativex = NativeX(this)
        themes = ThemeManager(this, nativex.container.prefs)
        edges = EdgeToEdgeController(this)
        player = VideoPlayerManager(this, nativex.auth)
        chooser = FileChooserHandler(this).also { it.register() }
        startup = AppStartupCoordinator(this, nativex.auth, nativex.assets, nativex.local) { msg, long ->
            nativex.toast(msg, long)
        }

        edges.setupInsets()
        if (SDK_INT >= 36) {
            onBackInvokedDispatcher.registerOnBackInvokedCallback(
                OnBackInvokedDispatcher.PRIORITY_DEFAULT,
            ) { onGoBack() }
        }
        edges.applyOrientation(resources.configuration.orientation)
        restoreTheme()

        nativex.doMediaSync(false)
        initializeWebView()
        player.hideInitial()
    }

    override fun onDestroy() {
        super.onDestroy()
        binding.webview.removeAllViews()
        binding.coordinator.removeAllViews()
        binding.webview.destroy()
        nativex.destroy()
    }

    override fun onConfigurationChanged(config: android.content.res.Configuration) {
        super.onConfigurationChanged(config)
        edges.applyOrientation(config.orientation)
    }

    public override fun onResume() {
        super.onResume()
        val uris = player.urisForRestore()
        val uid = player.uidForRestore()
        if (uris != null && uid != null) player.restoreIfNeeded(uris, uid)
        eventBus.onResumeRefresh()
    }

    public override fun onStop() {
        super.onStop()
        player.releasePlayer()
    }

    @SuppressLint("GestureBackNavigation")
    override fun onKeyDown(keyCode: Int, event: KeyEvent): Boolean {
        if (SDK_INT < 36 && event.action == KeyEvent.ACTION_DOWN && keyCode == KeyEvent.KEYCODE_BACK) {
            onGoBack()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    private fun onGoBack() {
        if (binding.webview.canGoBack()) binding.webview.goBack() else finish()
    }

    @SuppressLint("ClickableViewAccessibility")
    private fun initializeWebView() {
        binding.webview.webViewClient = MemoriesWebViewClient(this)
        binding.webview.webChromeClient = MemoriesWebChromeClient(this, chooser)
        binding.webview.setOnTouchListener { _, event ->
            player.dispatchTouch(event)
            false
        }
        val userAgent = getString(R.string.ua_app_prefix) + BuildConfig.VERSION_NAME + " " + WebSettings.getDefaultUserAgent(this)
        WebViewSetup.setup(binding.webview, userAgent, nativex, BuildConfig.DEBUG)
        nativex.account.refreshCredentials()
        try {
            nativex.local.ensureStarted()
        } catch (e: Exception) {
            Log.w(TAG, "Local server failed to start: ${e.message}")
            nativex.toast("Local server failed to start", true)
            return
        }
        // Per-process secret: without this cookie the local server rejects everything. Load only after it lands.
        CookieManager.getInstance().setCookie("http://127.0.0.1/", "local-auth=${nativex.local.secret}; Path=/") {
            runOnUiThread { startup.onLocalCookieReady() }
        }
        CookieManager.getInstance().flush()
    }

    /** Clears back history once after a main-page load; clearHistory() before loadUrl() alone does not. */
    fun consumeClearHistoryFlag(view: WebView) {
        if (clearHistoryOnLoad) {
            clearHistoryOnLoad = false
            view.clearHistory()
        }
    }

    fun loadDefaultUrl(): Boolean = startup.loadDefaultUrl()

    fun startLocalApp(toNxSetup: Boolean = false) = startup.startLocalApp(toNxSetup)

    fun loadLocalApp(subpath: String = ""): Boolean = startup.loadLocalApp(subpath)

    fun localStaticUrl(name: String): String = startup.localStaticUrl(name)

    fun initializePlayer(uris: Array<Uri>, uid: Long, loop: Boolean = false) = player.initializePlayer(uris, uid, loop)

    fun destroyPlayer(uid: Long) = player.destroyPlayer(uid)

    fun storeTheme(color: String?, isDark: Boolean) = themes.storeTheme(color, isDark)

    /** Tolerates calls before onCreate wiring by falling back to prefs-backed components. */
    fun restoreTheme() {
        if (!::themes.isInitialized) {
            val prefs = gallery.memories.data.local.prefs.PreferencesStore(this)
            themes = ThemeManager(this, prefs)
        }
        themes.restoreTheme()
    }

    fun applyTheme(color: String?, isDark: Boolean) = themes.applyTheme(color, isDark)

    /** Tolerates calls before onCreate wiring by falling back to a fresh controller. */
    fun setTransparentBars(transparent: Boolean, isDark: Boolean = true) {
        if (!::edges.isInitialized) edges = EdgeToEdgeController(this)
        edges.setTransparentBars(transparent, isDark)
    }

    fun refreshTimeline(force: Boolean = false) = eventBus.refreshTimeline(force)

    fun busEmit(event: String, data: String = "null") = eventBus.busEmit(event, data)

    fun setTrustAllCertificatesDefault() = TlsTrust.setDefaultInsecureTLS()

    fun onOrientationChanged(config: Configuration) = edges.applyOrientation(config.orientation)
}
