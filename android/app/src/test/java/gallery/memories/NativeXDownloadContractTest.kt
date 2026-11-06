package gallery.memories

import android.webkit.JavascriptInterface
import org.junit.Assert.assertTrue
import org.junit.Test

class NativeXDownloadContractTest {
    @Test
    fun olderServersCanDownloadWithoutNotificationTitle() {
        assertDownloadBridge(2)
    }

    @Test
    fun newerServersCanDownloadWithNotificationTitle() {
        assertDownloadBridge(3)
    }

    private fun assertDownloadBridge(argumentCount: Int) {
        // WebView dispatches by argument count and only exposes annotated methods.
        val method = NativeX::class.java.getMethod(
            "downloadFromUrl", *Array(argumentCount) { String::class.java },
        )
        assertTrue(method.isAnnotationPresent(JavascriptInterface::class.java))
    }
}
