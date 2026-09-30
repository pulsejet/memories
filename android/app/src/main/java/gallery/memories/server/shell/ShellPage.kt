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

    /** Content-Security-Policy for the shell: local pages only, API calls anywhere. */
    fun csp(nonce: String): String = listOf(
        "default-src 'self'",
        "script-src 'self' blob: 'nonce-$nonce'",
        "worker-src 'self' blob:",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob: https:",
        "media-src 'self' blob: data:",
        "connect-src 'self' https: http: wss: ws: data: blob:",
        "font-src 'self' data:",
        "frame-src 'self' https://www.openstreetmap.org",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ).joinToString("; ")

    /**
     * Renders the shell for a snapshot [describe].
     */
    fun build(
        describe: JSONObject,
        webRoot: String,
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
                script(src = "/local/assets/js/" + AssetCache.L10N_JS) {}
            }
            body {
                div { id = "skip-actions" }
                div { id = "content" }
                script {
                    attributes["nonce"] = nonce
                    unsafe {
                        val root = webRoot.jsString()
                        raw("window._oc_webroot = $root; window._oc_appswebroots = {'memories': $root + '/apps/memories'};")
                        raw(
                            """
                            window._oc_capabilities = {
                              'files': {
                                'forbidden_filename_characters': ['/', '\\'],
                                'forbidden_filenames': ['.htaccess'],
                                'forbidden_filename_basenames': [],
                                'forbidden_filename_extensions': ['.part', '.filepart']
                              }
                            };
                            """.trimIndent(),
                        )
                    }
                }
                script(src = "/local/assets/js/" + AssetCache.ENTRY_JS) { defer = true }
            }
        }
    }
}
