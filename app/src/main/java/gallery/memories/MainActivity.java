package gallery.memories;

import android.annotation.SuppressLint;
import android.os.Bundle;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.appcompat.app.AppCompatActivity;

import gallery.memories.databinding.ActivityMainBinding;

public class MainActivity extends AppCompatActivity {
    public static final String TAG = "memories-native";
    protected ActivityMainBinding binding;
    protected NativeX mNativeX;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        binding = ActivityMainBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        mNativeX = new NativeX(this, binding.webview);
        initializeWebView();
    }

    @SuppressLint("SetJavaScriptEnabled")
    protected void initializeWebView() {
        binding.webview.setWebViewClient(new WebViewClient() {
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                view.loadUrl(request.getUrl().toString());
                return false;
            }

            @Override
            public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                if (request.getUrl().getHost().equals("127.0.0.1")) {
                    return mNativeX.handleRequest(request);
                }
                return null;
            }
        });

        WebSettings webSettings = binding.webview.getSettings();
        webSettings.setJavaScriptEnabled(true);
        webSettings.setJavaScriptCanOpenWindowsAutomatically(true);
        webSettings.setAllowContentAccess(true);
        webSettings.setDomStorageEnabled(true);
        webSettings.setDatabaseEnabled(true);
        webSettings.setUserAgentString("memories-native-android/0.0");

        binding.webview.clearCache(true);
        binding.webview.addJavascriptInterface(mNativeX, "nativex");
        binding.webview.loadUrl("http://10.0.2.2:8035/index.php/apps/memories/");
    }
}