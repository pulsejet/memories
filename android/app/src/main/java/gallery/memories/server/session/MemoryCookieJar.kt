package gallery.memories.server.session

import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.HttpUrl

/** One upstream session's cookies; no persistence needed. */
class MemoryCookieJar : CookieJar {
    private val lock = Any()
    private val store = mutableListOf<Cookie>()

    override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
        synchronized(lock) {
            for (cookie in cookies) {
                store.removeAll { it.name == cookie.name && it.domain == cookie.domain && it.path == cookie.path }
                if (cookie.persistent && cookie.expiresAt <= System.currentTimeMillis()) continue
                store.add(cookie)
            }
        }
    }

    override fun loadForRequest(url: HttpUrl): List<Cookie> {
        synchronized(lock) {
            val now = System.currentTimeMillis()
            store.removeAll { it.persistent && it.expiresAt <= now }
            return store.filter { it.matches(url) }.toList()
        }
    }
}
