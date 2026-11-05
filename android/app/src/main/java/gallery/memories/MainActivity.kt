package gallery.memories

import android.os.Bundle
import android.util.Log
import android.webkit.CookieManager
import android.webkit.WebSettings
import android.webkit.WebView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.media3.common.util.UnstableApi
import gallery.memories.data.local.prefs.PreferencesStore
import gallery.memories.databinding.ActivityMainBinding
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
/**
 * Single-activity host: owns the WebView and UI components, delegating
 * business logic to them. All [NativeX] bridge entry points funnel through here.
 */
class MainActivity : AppCompatActivity() {
    companion object {
        val TAG: String = MainActivity::class.java.simpleName
    }

    val binding by lazy(LazyThreadSafetyMode.NONE) {
        ActivityMainBinding.inflate(layoutInflater)
    }

    /** Background pool for bridge/DB work. Never submit UI work to it. */
    val threadPool: ExecutorService = Executors.newFixedThreadPool(4)

    lateinit var nativex: NativeX
        private set

    private lateinit var themes: ThemeManager
    private lateinit var edges: EdgeToEdgeController
    lateinit var eventBus: JsEventBus
        private set
    private lateinit var chooser: FileChooserHandler
    private lateinit var startup: AppStartupCoordinator

    /** False until the local server is up and the first page load is scheduled; retried on resume. */
    private var booted = false

    /** One-shot: cleared by the web client after the main page loads so Back skips entry pages. */
    var clearHistoryOnLoad = false

    /**
     * Enabled only while the page has history; otherwise the system default
     * (background the task) applies. Uses the page's own history.back() since
     * WebView's goBack() skips entries pushed without a user gesture.
     */
    private val backCallback = object : OnBackPressedCallback(false) {
        override fun handleOnBackPressed() {
            binding.webview.evaluateJavascript("history.back()", null)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(binding.root)

        eventBus = JsEventBus(this)
        nativex = NativeX(this)
        themes = ThemeManager(this, nativex.container.prefs)
        edges = EdgeToEdgeController(this)
        chooser = FileChooserHandler(this).also { it.register() }
        startup = AppStartupCoordinator(this, nativex.auth, nativex.assets, nativex.local) { msg, long ->
            nativex.toast(msg, long)
        }

        edges.setupInsets()
        onBackPressedDispatcher.addCallback(this, backCallback)
        edges.applyOrientation(resources.configuration.orientation)
        restoreTheme()

        nativex.doMediaSync(false)
        initializeWebView()
    }

    override fun onDestroy() {
        super.onDestroy()
        binding.webview.removeAllViews()
        binding.coordinator.removeAllViews()
        binding.webview.destroy()
        nativex.destroy()
        threadPool.shutdownNow()
    }

    override fun onConfigurationChanged(config: android.content.res.Configuration) {
        super.onConfigurationChanged(config)
        edges.applyOrientation(config.orientation)
    }

    public override fun onPause() {
        super.onPause()
        binding.webview.onPause()
        binding.webview.pauseTimers()
        runCatching { nativex.container.holeVideo.pause() }
    }

    public override fun onResume() {
        super.onResume()
        binding.webview.onResume()
        binding.webview.resumeTimers()
        binding.webview.invalidate()
        eventBus.onResumeRefresh()
        runCatching { nativex.container.holeVideo.resume() }
        if (booted) nativex.local.attach() else bootLocalServer()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus && !isFinishing && !isDestroyed) {
            binding.webview.onResume()
            binding.webview.resumeTimers()
            binding.webview.invalidate()
        }
    }

    private fun initializeWebView() {
        binding.webview.webViewClient = MemoriesWebViewClient(this)
        binding.webview.webChromeClient = MemoriesWebChromeClient(this, chooser)
        val userAgent = getString(R.string.ua_app_prefix) + BuildConfig.VERSION_NAME + " " + WebSettings.getDefaultUserAgent(this)
        WebViewSetup.setup(binding.webview, userAgent, nativex, BuildConfig.DEBUG)
        nativex.account.refreshCredentials()
    }

    /** Starts (or reattaches to) the process-wide local server, then loads the first page. */
    private fun bootLocalServer() {
        try {
            nativex.local.ensureStarted()
        } catch (e: Exception) {
            Log.w(TAG, "Local server failed to start: ${e.message}")
            nativex.toast("Local server failed to start", true)
            return
        }
        booted = true
        nativex.attachAsyncMessages()
        // Per-process secret as HttpOnly cookie: without it the local server rejects everything. Load only after it lands.
        CookieManager.getInstance().setCookie("http://127.0.0.1/", nativex.local.authCookie()) {
            runOnUiIfAlive { startup.onLocalCookieReady() }
        }
        CookieManager.getInstance().flush()
    }

    /** Clears back history once the main page lands; clearHistory() before loadUrl() alone does not. */
    fun consumeClearHistoryFlag(view: WebView) {
        if (clearHistoryOnLoad) {
            clearHistoryOnLoad = false
            view.clearHistory()
        }
    }

    fun syncBackCallback(view: WebView) {
        backCallback.isEnabled = view.copyBackForwardList().currentIndex > 0
    }

    val isAlive: Boolean get() = !isFinishing && !isDestroyed

    /** Like [runOnUiThread], but drops [block] once the activity is finishing or destroyed. */
    fun runOnUiIfAlive(block: () -> Unit) = runOnUiThread { if (isAlive) block() }

    fun loadDefaultUrl(): Boolean = startup.loadDefaultUrl()

    /** Boots the locally served app, optionally into the first-run setup flow. */
    fun startLocalApp(toNxSetup: Boolean = false) = startup.startLocalApp(toNxSetup)

    /** Loads the cached snapshot's shell at [subpath]. False when no snapshot is ready. */
    fun loadLocalApp(subpath: String = ""): Boolean = startup.loadLocalApp(subpath)

    /** URL of a static entry page (welcome/waiting) bundled in the APK. */
    fun localStaticUrl(name: String): String = startup.localStaticUrl(name)

    fun storeTheme(color: String?, isDark: Boolean) = themes.storeTheme(color, isDark)

    /** Tolerates calls before onCreate wiring by falling back to prefs-backed components. */
    fun restoreTheme() {
        if (!::themes.isInitialized) {
            val prefs = PreferencesStore(this)
            themes = ThemeManager(this, prefs)
        }
        themes.restoreTheme()
    }

    fun applyTheme(color: String?, isDark: Boolean) = themes.applyTheme(color, isDark)

    fun refreshTimeline(force: Boolean = false) = eventBus.refreshTimeline(force)
}
