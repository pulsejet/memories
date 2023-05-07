package gallery.memories;

import android.os.Bundle;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.appcompat.app.AppCompatActivity;

import gallery.memories.databinding.ActivityMainBinding;
import gallery.memories.service.ImageService;
import gallery.memories.service.JsService;
import gallery.memories.service.TimelineQuery;

public class MainActivity extends AppCompatActivity {
    public static final String TAG = "memories-native";
    protected ActivityMainBinding binding;

    protected JsService mJsService;
    protected ImageService mImageService;
    protected TimelineQuery mQuery;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        binding = ActivityMainBinding.inflate(getLayoutInflater());
        setContentView(binding.getRoot());

        mJsService = new JsService(this, binding.webview);
        mImageService = new ImageService(this);
        mQuery = new TimelineQuery(this);

        initWebview();
    }

    protected void initWebview() {
        binding.webview.setWebViewClient(new WebViewClient() {
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                view.loadUrl(request.getUrl().toString());
                return false;
            }
        });

        WebSettings webSettings = binding.webview.getSettings();
        webSettings.setJavaScriptEnabled(true);
        webSettings.setJavaScriptCanOpenWindowsAutomatically(true);
        webSettings.setAllowContentAccess(true);
        webSettings.setDomStorageEnabled(true);
        webSettings.setDatabaseEnabled(true);
        webSettings.setUserAgentString("memories-native-android/0.0");

        binding.webview.addJavascriptInterface(this, "nativex");
        binding.webview.loadUrl("http://10.0.2.2:8035/index.php/apps/memories/");
    }

    @JavascriptInterface
    public boolean isNative() {
        return true;
    }

    @JavascriptInterface
    public void getLocalByDayId(final String call, final long dayId) {
        mJsService.runAsync(call, () -> mQuery.getByDayId(dayId).toString().getBytes());
    }

    @JavascriptInterface
    public void getJpeg(final String call, final String uri) {
        mJsService.runAsync(call, () -> mImageService.getFromURI(uri));
    }
}