package gallery.memories.data.remote.http

import android.content.Context
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.HttpUrl
import org.json.JSONArray
import org.json.JSONObject

/**
 * Nextcloud session cookies, persisted across restarts.
 *
 * Every request authenticated with Basic + app password runs a full server
 * login, which replays the whole post-login chain (external-mount cache
 * rebuild, file-cache cleanup, full filesystem mount initialization: ~18
 * extra DB queries plus storage setup) before app code even executes.
 * Measured at ~1s median per request vs ~0.06s with cookies (17x).
 *
 * Requests carrying a valid session cookie skip all of this because the
 * session already identifies the user, so no login runs. Since the server
 * side can't be fixed, the client keeps the session alive: the `oc*`
 * cookies (including `oc_sessionPassphrase`) returned by the first
 * Basic-auth request are stored here and sent on subsequent ones, turning
 * every later call into the fast session path.
 *
 * Basic auth is still sent on every call as an automatic fallback: if the
 * session lapsed, the server just logs in again (one slow call) and the
 * jar refreshes itself from the fresh `Set-Cookie`s.
 */
class SessionCookieJar(context: Context) : CookieJar {
    companion object {
        private const val PREFS = "session_cookies"
        private const val KEY = "cookies"
        private const val MAX_COOKIES = 64
    }

    private val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    private val cookies = load().toMutableList()

    @Synchronized
    override fun loadForRequest(url: HttpUrl): List<Cookie> {
        val now = System.currentTimeMillis()
        return cookies.filter { it.expiresAt >= now && it.matches(url) }
    }

    @Synchronized
    override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
        if (cookies.isEmpty()) return
        for (c in cookies) {
            this.cookies.removeAll { it.name == c.name && it.domain == c.domain && it.path == c.path }
            this.cookies.add(c)
        }
        val now = System.currentTimeMillis()
        this.cookies.removeAll { it.expiresAt < now }
        while (this.cookies.size > MAX_COOKIES) this.cookies.removeAt(0)
        persist()
    }

    @Synchronized
    fun clear() {
        cookies.clear()
        prefs.edit().remove(KEY).apply()
    }

    private fun load(): List<Cookie> = try {
        val raw = prefs.getString(KEY, null) ?: return emptyList()
        val arr = JSONArray(raw)
        val now = System.currentTimeMillis()
        List(arr.length()) { parse(arr.getJSONObject(it)) }
            .filterNotNull()
            .filter { it.expiresAt >= now }
    } catch (_: Exception) {
        emptyList()
    }

    private fun persist() = try {
        val arr = JSONArray()
        for (c in cookies) {
            arr.put(JSONObject()
                .put("name", c.name).put("value", c.value)
                .put("expiresAt", c.expiresAt)
                .put("domain", c.domain).put("path", c.path)
                .put("secure", c.secure).put("httpOnly", c.httpOnly)
                .put("hostOnly", c.hostOnly))
        }
        prefs.edit().putString(KEY, arr.toString()).apply()
    } catch (_: Exception) {}

    private fun parse(o: JSONObject): Cookie? = try {
        val b = Cookie.Builder()
            .name(o.getString("name")).value(o.getString("value"))
            .expiresAt(o.getLong("expiresAt"))
            .path(o.optString("path", "/"))
        val domain = o.getString("domain")
        if (o.optBoolean("hostOnly", true)) b.hostOnlyDomain(domain) else b.domain(domain)
        if (o.optBoolean("secure", false)) b.secure()
        if (o.optBoolean("httpOnly", false)) b.httpOnly()
        b.build()
    } catch (_: Exception) {
        null
    }
}
