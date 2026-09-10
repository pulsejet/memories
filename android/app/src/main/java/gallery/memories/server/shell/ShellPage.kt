package gallery.memories.server.shell

import kotlinx.html.body
import kotlinx.html.div
import kotlinx.html.head
import kotlinx.html.html
import kotlinx.html.id
import kotlinx.html.link
import kotlinx.html.meta
import kotlinx.html.script
import kotlinx.html.stream.createHTML
import kotlinx.html.title
import kotlinx.html.unsafe
import gallery.memories.data.remote.assets.AssetCache
import org.json.JSONObject

/** Offline equivalent of the server page: local scripts/styles plus a nonced bootstrap snippet. */
object ShellPage {
    /** Single-quoted JS string literal for a trusted server path. Escapes backslash/quote only. */
    private fun String.jsString(): String =
        "'" + replace("\\", "\\\\").replace("'", "\\'") + "'"

    /** Content-Security-Policy for the shell: self plus the per-request bootstrap nonce. */
    fun csp(nonce: String): String =
        "script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'"

    /**
     * Renders the shell for a snapshot [describe].
     *
     * @param debug reserved for a future unminified/debug shell; currently ignored.
     */
    @Suppress("UNUSED_PARAMETER")
    fun build(
        describe: JSONObject,
        webRoot: String,
        baseUrl: String,
        debug: Boolean = false,
        user: String? = null,
        nonce: String,
    ): String {
        return "<!DOCTYPE html>\n" + createHTML().html {
            head {
                if (user != null) attributes["data-user"] = user
                meta(charset = "utf-8")
                meta(name = "viewport", content = "width=device-width, initial-scale=1")
                title { +"Memories" }
                val css = describe.optJSONArray("cssManifest")
                if (css != null) {
                    for (i in 0 until css.length()) {
                        val attrs = css.getJSONObject(i)
                        link(
                            href = "/local/assets/css/" + AssetCache.cssName(i, attrs.getString("href")),
                            rel = attrs.optString("rel").ifEmpty { "stylesheet" },
                        ) {
                            attrs.keys().forEach { name ->
                                if (name != "href" && name != "rel" && !attrs.isNull(name)) {
                                    attributes[name] = attrs.getString(name)
                                }
                            }
                        }
                    }
                }
                link(href = "/local/static/shell-overrides.css", rel = "stylesheet")
            }
            body {
                div { id = "skip-actions" }
                div {
                    id = "content"
                    attributes["data-base-url"] = baseUrl
                }
                script {
                    attributes["nonce"] = nonce
                    unsafe {
                        val root = webRoot.jsString()
                        raw("window._oc_webroot = $root; window._oc_appswebroots = {'memories': $root + '/apps/memories'};")
                    }
                }
                script(src = "/local/assets/js/" + AssetCache.ENTRY_JS) { defer = true }
            }
        }
    }
}
