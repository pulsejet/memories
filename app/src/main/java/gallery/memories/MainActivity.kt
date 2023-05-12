package gallery.memories

import android.annotation.SuppressLint
import android.os.Bundle
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
import gallery.memories.databinding.ActivityMainBinding

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private lateinit var mNativeX: NativeX

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Initialize services
        mNativeX = NativeX(this)

        // Load JavaScript
        initializeWebView()
    }

    @SuppressLint("SetJavaScriptEnabled")
    protected fun initializeWebView() {
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
    }
}