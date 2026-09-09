package gallery.memories.service

import android.content.Context
import android.util.Base64
import android.util.Log
import androidx.core.net.toUri
import io.github.g00fy2.versioncompare.Version
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/**
 * Downloads and caches the web app's JS/CSS assets for offline serving.
 * Driven by api/describe?manifest=1 after the user selects a server.
 */
class AssetService(private val mCtx: Context, private val mHttp: HttpService) {
    companion object {
        val TAG: String = AssetService::class.java.simpleName
        const val ENTRY_JS = "memories-main.js"

        fun cssName(i: Int, href: String): String {
            return "%02d_%s".format(i, href.substringAfterLast("/").substringBefore("?"))
        }

        fun originOf(baseUrl: String): String {
            val uri = baseUrl.toUri()
            val port = uri.port.takeIf { it > 0 }?.let { ":$it" } ?: ""
            return "${uri.scheme}://${uri.host}$port"
        }

        /** Web root of the server, e.g. "" or "/nextcloud". */
        fun webrootOf(baseUrl: String): String {
            val path = baseUrl.toUri().path ?: ""
            return path.substringBefore("/index.php/")
        }
    }

    data class JsAsset(val name: String, val hash: String, val href: String)

    @Volatile var status = "idle"
    @Volatile var total = 0
    @Volatile var done = 0
    @Volatile var error: String? = null

    /** Snapshot chosen by the last freshness check (may be newer than latest). */
    @Volatile var current: Pair<String, File>? = null
        private set

    private val lock = Any()
    private var running = false
    private var pending: JSONObject? = null

    fun progressJson(): JSONObject {
        return JSONObject()
            .put("status", status)
            .put("done", done)
            .put("total", total)
            .put("error", error)
    }

    /** Directory holding one server+version snapshot of assets. */
    private fun dirFor(baseUrl: String, version: String): File {
        return File(serverDir(baseUrl), version)
    }

    private fun serverDir(baseUrl: String): File {
        val uri = baseUrl.toUri()
        val port = uri.port.takeIf { it > 0 }?.toString() ?: ""
        val safe = "${uri.scheme}_${uri.host}$port".replace(Regex("[^A-Za-z0-9_.-]"), "_")
        return File(mCtx.filesDir, "offline/$safe")
    }

    /** Newest ready snapshot for a server, if any. */
    fun latestSnapshot(baseUrl: String): Pair<String, File>? {        return try {
            val root = serverDir(baseUrl)
            if (!root.isDirectory) return null
            root.listFiles()
                ?.filter { it.isDirectory && File(it, ".ready").exists() }
                ?.maxWithOrNull { a, b -> Version(a.name).compareTo(Version(b.name)) }
                ?.let { it.name to it }
        } catch (_: Exception) {
            null
        }
    }

    fun readDescribe(dir: File): JSONObject? {
        return try {
            JSONObject(File(dir, "describe.json").readText())
        } catch (_: Exception) {
            null
        }
    }

    fun hasSnapshot(baseUrl: String): Boolean {
        return try {
            latestSnapshot(baseUrl) != null
        } catch (_: Exception) {
            false
        }
    }

    /** Fresh describe?manifest=1, or null when unreachable. Background only. */
    fun fetchDescribe(): JSONObject? {
        return try {
            mHttp.getApiDescriptionManifest().use { res ->
                if (res.code != 200) null else mHttp.bodyJson(res)
            }
        } catch (_: Exception) {
            null
        }
    }

    /** True if the snapshot matching this manifest is already on disk. */
    fun isCurrent(fresh: JSONObject): Boolean {
        val base = fresh.optString("baseUrl", "")
        val version = fresh.optString("version", "")
        if (base.isEmpty() || version.isEmpty()) return false
        val dir = dirFor(base, version)
        if (!File(dir, ".ready").exists()) return false
        val cur = readDescribe(dir) ?: return false
        return manifestKey(cur) == manifestKey(fresh)
    }

    /** Content key of a manifest: version-independent, catches hash changes. */
    private fun manifestKey(describe: JSONObject): String {
        // opt() covers both a missing key and an explicit JSON null
        return describe.optString("jsManifest", "") + "\n" + describe.opt("cssManifest")
    }

    /**
     * Make sure a current snapshot exists, downloading first if needed.
     * BLOCKS the caller: run on a background thread only.
     * Shows progress through status/done/total for the waiting page.
     */
    fun ensureFreshAndWait(timeoutMs: Long = 15 * 60 * 1000): Boolean {
        return try {
            ensureFreshInner(timeoutMs)
        } catch (e: Exception) {
            Log.w(TAG, "Freshness check failed", e)
            status = "error"
            error = e.message
            false
        }
    }

    private fun ensureFreshInner(timeoutMs: Long): Boolean {
        val base = mHttp.baseUrl() ?: return false

        val fresh = fetchDescribe()
        if (fresh == null) {
            // Offline: any ready snapshot will do, never remote JS
            val snap = latestSnapshot(base) ?: run {
                status = "error"
                error = "No connection and no offline files"
                return false
            }
            return useSnapshot(snap)
        }

        val snap = latestSnapshot(base)
        val cur = snap?.let { readDescribe(it.second) }
        if (snap != null && cur != null && manifestKey(cur) == manifestKey(fresh)) {
            return useSnapshot(snap)
        }

        if (!syncAndAwait(fresh, timeoutMs)) return false
        val version = fresh.getString("version")
        return useSnapshot(version to dirFor(base, version))
    }

    /** Adopt a snapshot as current. */
    private fun useSnapshot(snap: Pair<String, File>): Boolean {
        current = snap
        status = "ready"
        return true
    }

    /**
     * Sync one manifest and wait for it, following queued retries:
     * an error only settles the wait if no retry is still running.
     */
    fun syncAndAwait(fresh: JSONObject, timeoutMs: Long): Boolean {
        sync(fresh)
        val deadline = System.currentTimeMillis() + timeoutMs
        synchronized(lock) {
            while (true) {
                if (System.currentTimeMillis() >= deadline) return false
                if (status == "ready") return true
                if ((status == "error" || status == "idle") && !running) return false
                try {
                    (lock as java.lang.Object).wait(deadline - System.currentTimeMillis())
                } catch (_: InterruptedException) {
                    if (!running) return false
                }
            }
        }
    }

    /** Start a background sync from a describe?manifest=1 response. Never throws. */
    fun sync(describe: JSONObject) {
        synchronized(lock) {
            pending = describe
            if (running) return
            running = true
        }
        Thread {
            try {
                while (true) {
                    val next: JSONObject? = synchronized(lock) {
                        pending.also { pending = null }
                    }
                    if (next == null) break
                    if (!syncOne(next)) break
                }
            } finally {
                synchronized(lock) {
                    running = false
                    (lock as java.lang.Object).notifyAll()
                }
            }
        }.start()
    }

    /** Sync one manifest. Returns false on error (status already set). */
    private fun syncOne(describe: JSONObject): Boolean {
        try {
            val version = describe.getString("version")
            val baseUrl = describe.getString("baseUrl")
            val dir = dirFor(baseUrl, version)

            // Same content already on disk (version-independent check)
            val existing = readDescribe(dir)?.takeIf { File(dir, ".ready").exists() }
            if (existing != null && manifestKey(existing) == manifestKey(describe)) {
                status = "ready"
                return true
            }

            syncBlocking(describe, version, baseUrl, dir)
            status = "ready"
            return true
        } catch (e: Exception) {
            Log.w(TAG, "Asset sync failed", e)
            status = "error"
            error = e.message
            return false
        }
    }

    @Throws(Exception::class)
    private fun syncBlocking(describe: JSONObject, version: String, baseUrl: String, dir: File) {
        val origin = originOf(baseUrl)

        val jsB64 = describe.optString("jsManifest", null)
            ?: throw Exception("Server has no asset manifest")
        val jsJson = JSONObject(String(Base64.decode(jsB64, Base64.DEFAULT)))
        val js = jsJson.keys().asSequence()
            .filter { it.endsWith(".js") || it.endsWith(".mjs") }
            .mapNotNull {
                val o = jsJson.getJSONObject(it)
                val hash = o.optString("hash", null)?.ifEmpty { null } ?: return@mapNotNull null
                JsAsset(it, hash, o.getString("href"))
            }.toList()
        val css = describe.optJSONArray("cssManifest") ?: JSONArray()

        total = js.size + css.length()
        done = 0
        status = "downloading"
        error = null

        // Fresh snapshot directory
        dir.deleteRecursively()
        val jsDir = File(dir, "js").apply { mkdirs() }
        val cssDir = File(dir, "css").apply { mkdirs() }

        for (asset in js) {
            downloadTo(jsDir, asset.name, absUrl(origin, asset.href), asset.hash)
        }

        for (i in 0 until css.length()) {
            val href = css.getJSONObject(i).getString("href")
            downloadTo(cssDir, cssName(i, href), absUrl(origin, href))
        }

        File(dir, "describe.json").writeText(describe.toString())
        File(dir, ".ready").createNewFile()
        status = "ready"
        Log.i(TAG, "Asset sync complete: $done files for $origin@$version")
    }

    @Throws(Exception::class)
    private fun downloadTo(dir: File, name: String, url: String, expectedSha256: String? = null) {
        val bytes = mHttp.downloadBytes(url)
        if (expectedSha256 != null) verifySha256(bytes, expectedSha256, name)
        File(dir, name).writeBytes(bytes)
        done++
    }

    @Throws(Exception::class)
    private fun verifySha256(bytes: ByteArray, expected: String, name: String) {
        val actual = MessageDigest.getInstance("SHA-256").digest(bytes)
            .joinToString("") { "%02x".format(it) }
        if (!actual.equals(expected, ignoreCase = true)) {
            throw Exception("SHA-256 mismatch for $name: expected $expected, got $actual")
        }
    }

    private fun absUrl(origin: String, href: String): String {
        return if (href.startsWith("http://") || href.startsWith("https://")) href
        else origin + href
    }
}
