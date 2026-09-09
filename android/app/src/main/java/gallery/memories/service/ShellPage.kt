package gallery.memories.service

import kotlinx.html.body
import kotlinx.html.div
import kotlinx.html.head
import kotlinx.html.html
import kotlinx.html.id
import kotlinx.html.link
import kotlinx.html.meta
import kotlinx.html.pre
import kotlinx.html.script
import kotlinx.html.stream.createHTML
import kotlinx.html.title
import kotlinx.html.unsafe
import org.json.JSONObject

/**
 * Builds the local app shell page (offline equivalent of the server page).
 * Scripts load from the local origin only (plus one nonced inline bootstrap
 * snippet); styles load locally or inline. Request token and user headers
 * are seeded as head attributes like the server page.
 */
object ShellPage {
    /** Content Security Policy for the shell, keyed by a per-response nonce. */
    fun csp(nonce: String): String {
        return "script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'"
    }

    fun build(
        describe: JSONObject,
        webRoot: String,
        baseUrl: String,
        debug: Boolean = false,
        requestToken: String? = null,
        user: String? = null,
        nonce: String,
    ): String {
        return "<!DOCTYPE html>\n" + createHTML().html {
            head {
                if (requestToken != null) attributes["data-requesttoken"] = requestToken
                if (user != null) attributes["data-user"] = user
                meta(charset = "utf-8")
                meta(name = "viewport", content = "width=device-width, initial-scale=1")
                title { +"Memories" }

                val css = describe.optJSONArray("cssManifest")
                if (css != null) {
                    for (i in 0 until css.length()) {
                        val attrs = css.getJSONObject(i)
                        link(
                            href = "/local/assets/css/" + AssetService.cssName(
                                i, attrs.getString("href")
                            ),
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
                        raw("window._oc_webroot = ''; window._oc_appswebroots = {'memories': '/apps/memories'};")
                    }
                }
                script(src = "/local/assets/js/" + AssetService.ENTRY_JS) {
                    defer = true
                }
            }
        }
    }
}
