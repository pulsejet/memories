package gallery.memories.data.remote.assets

import android.util.Base64
import android.util.Log
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.NextcloudApi
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.util.concurrent.ExecutionException
import java.util.concurrent.Executors

/**
 * Downloads and caches the web app's JS/CSS for offline serving.
 * Syncs run on a worker thread; callers observe progress via status/done/total.
 *
 * Only one sync runs at a time; a newer describe queued mid-sync is picked
 * up immediately after (see [sync]).
 */
class AssetSyncCoordinator(
    private val auth: AuthState,
    private val api: NextcloudApi,
    private val cache: AssetCache,
    private val downloader: AssetDownloader,
    private val onError: (String) -> Unit = {},
) {
    companion object {
        private val TAG = AssetSyncCoordinator::class.java.simpleName
        const val MAX_PARALLEL = 8
    }

    /** One downloadable web asset: remote [href], verified against [hash] for JS. */
    data class JsAsset(val name: String, val hash: String, val href: String)

    /** One downloadable stylesheet: local [name] derived from its position and remote [href]. */
    data class CssAsset(val name: String, val href: String)

    /** One queued file download within [syncBlocking]. */
    private data class DownloadTask(val dir: File, val name: String, val url: String, val hash: String?)

    /** Lifecycle: idle → downloading → ready | error. */
    @Volatile var status = "idle"
    @Volatile var total = 0
    @Volatile var error: String? = null
    val done: Int get() = downloader.done

    /** Currently served snapshot (manifest hash, dir), null before the first sync. */
    @Volatile var current: Pair<String, File>? = null
        private set

    private val lock = Any()
    private var running = false
    private var pending: JSONObject? = null

    fun progressJson(): JSONObject = JSONObject()
        .put("status", status).put("done", done).put("total", total).put("error", error)

    fun latestSnapshot(baseUrl: String) = cache.latestSnapshot(baseUrl)
    fun readDescribe(dir: File) = cache.readDescribe(dir)
    fun hasSnapshot(baseUrl: String) = cache.hasSnapshot(baseUrl)

    /** Fetches describeApi and refuses manifests with a missing or invalid signature. */
    fun fetchDescribe(): JSONObject? {
        return try {
            api.getApiDescriptionManifest().use { res ->
                if (res.code != 200) return@use null
                val body = api.bodyJson(res) ?: return@use null
                if (!ManifestVerifier.verifyDescribe(body)) {
                    onError("Asset manifest signature verification failed")
                    return@use null
                }
                body
            }
        } catch (_: Exception) {
            null
        }
    }

    /** True when the snapshot for [fresh]'s manifest is already downloaded and ready. */
    fun isCurrent(fresh: JSONObject): Boolean {
        val base = fresh.optString("baseUrl", "")
        if (base.isEmpty()) return false
        val dir = cache.dirFor(base, cache.snapshotKey(fresh))
        if (!File(dir, ".ready").exists()) return false
        val cur = cache.readDescribe(dir) ?: return false
        return !cache.jsChanged(cur, fresh) && !cache.cssChanged(cur, fresh)
    }

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
        val base = auth.baseUrl() ?: return false
        val fresh = fetchDescribe()
        if (fresh == null) {
            val snap = cache.latestSnapshot(base) ?: run {
                status = "error"
                error = "No connection and no offline files"
                return false
            }
            return useSnapshot(snap)
        }
        val snap = cache.latestSnapshot(base)
        val cur = snap?.let { cache.readDescribe(it.second) }
        if (snap != null && cur != null && !cache.jsChanged(cur, fresh) && !cache.cssChanged(cur, fresh)) {
            return useSnapshot(snap)
        }
        if (!syncAndAwait(fresh, timeoutMs)) return false
        val key = cache.snapshotKey(fresh)
        return useSnapshot(key to cache.dirFor(base, key))
    }

    /** Serves [snap] as the current snapshot. */
    private fun useSnapshot(snap: Pair<String, File>): Boolean {
        current = snap
        status = "ready"
        return true
    }

    /**
     * Queues a sync and blocks until it finishes or [timeoutMs] elapses.
     * True only when the fresh describe is ready to serve.
     */
    @Suppress("PLATFORM_CLASS_MAPPED_TO_KOTLIN") // wait() needs java.lang.Object
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

    /**
     * Queues [describe] for download on a worker thread. Coalesces: when a
     * sync is already running, the newest queued describe runs right after.
     */
    @Suppress("PLATFORM_CLASS_MAPPED_TO_KOTLIN") // notifyAll() needs java.lang.Object
    fun sync(describe: JSONObject) {
        synchronized(lock) {
            pending = describe
            // Mark synchronously: a waiter must not mistake a previous
            // "ready" for this sync (its snapshot dir is deleted on start).
            status = "downloading"
            if (running) return
            running = true
        }
        Thread {
            try {
                while (true) {
                    val next: JSONObject? = synchronized(lock) { pending.also { pending = null } }
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

    private fun syncOne(describe: JSONObject): Boolean {
        try {
            val baseUrl = describe.getString("baseUrl")
            val key = cache.snapshotKey(describe)
            val dir = cache.dirFor(baseUrl, key)
            val existing = cache.readDescribe(dir)?.takeIf { File(dir, ".ready").exists() }
            if (existing != null && !cache.jsChanged(existing, describe) && !cache.cssChanged(existing, describe)) {
                cache.pruneExcept(baseUrl, key)
                status = "ready"
                return true
            }
            syncBlocking(describe, baseUrl, dir)
            cache.pruneExcept(baseUrl, key)
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
    private fun syncBlocking(describe: JSONObject, baseUrl: String, dir: File) {
        val origin = AssetCache.originOf(baseUrl)
        val js = parseJsAssets(describe)
        val css = parseCssAssets(describe)

        // Previous snapshot to reuse unchanged files from (different dir by key).
        val prev = cache.latestSnapshot(baseUrl)?.takeIf { it.second.absolutePath != dir.absolutePath }
        val prevDescribe = prev?.let { cache.readDescribe(it.second) }
        val prevJsHashes = try {
            prevDescribe?.let { parseJsAssets(it).associate { a -> a.name to a.hash } }
        } catch (_: Exception) {
            null
        }
        val reuseCss = prev != null && prevDescribe != null && !cache.cssChanged(prevDescribe, describe)
        val prevJsDir = prev?.let { File(it.second, "js") }
        val prevCssDir = prev?.let { File(it.second, "css") }

        downloader.reset()
        status = "downloading"
        error = null
        dir.deleteRecursively()
        val jsDir = File(dir, "js").apply { mkdirs() }
        val cssDir = File(dir, "css").apply { mkdirs() }

        val tasks = ArrayList<DownloadTask>(js.size + css.size)
        var reused = 0
        for (asset in js) {
            val prevFile = prevJsDir?.let { File(it, asset.name) }
            if (prevFile?.isFile == true && prevJsHashes?.get(asset.name) == asset.hash) {
                prevFile.copyTo(File(jsDir, asset.name), overwrite = true)
                reused++
            } else {
                tasks.add(DownloadTask(jsDir, asset.name, absUrl(origin, asset.href), asset.hash))
            }
        }
        for (asset in css) {
            // CSS has no content hash, so reuse only when the whole manifest is unchanged.
            val prevFile = prevCssDir?.let { File(it, asset.name) }
            if (reuseCss && prevFile?.isFile == true) {
                prevFile.copyTo(File(cssDir, asset.name), overwrite = true)
                reused++
            } else {
                tasks.add(DownloadTask(cssDir, asset.name, absUrl(origin, asset.href), null))
            }
        }
        total = tasks.size

        if (tasks.isNotEmpty()) {
            val pool = Executors.newFixedThreadPool(minOf(tasks.size, MAX_PARALLEL))
            try {
                val futures = tasks.map { t ->
                    pool.submit { downloader.downloadTo(t.dir, t.name, t.url, t.hash) }
                }
                try {
                    for (f in futures) f.get()
                } catch (e: ExecutionException) {
                    pool.shutdownNow()
                    throw e.cause as? Exception ?: Exception(e.cause)
                } catch (e: InterruptedException) {
                    pool.shutdownNow()
                    Thread.currentThread().interrupt()
                    throw Exception("interrupted", e)
                }
            } finally {
                pool.shutdown()
            }
        }
        File(dir, "describe.json").writeText(describe.toString())
        File(dir, ".ready").createNewFile()
        status = "ready"
        Log.i(TAG, "Asset sync complete: ${downloader.done} downloaded, $reused reused for $origin")
    }

    @Throws(Exception::class)
    private fun parseJsAssets(describe: JSONObject): List<JsAsset> {
        val jsB64 = describe.optString("jsManifest", "").ifEmpty { null } ?: throw Exception("Server has no asset manifest")
        val jsJson = JSONObject(String(Base64.decode(jsB64, Base64.DEFAULT)))
        return jsJson.keys().asSequence()
            .filter { it.endsWith(".js") || it.endsWith(".mjs") }
            .mapNotNull {
                val o = jsJson.getJSONObject(it)
                val hash = o.optString("hash", "").ifEmpty { null } ?: return@mapNotNull null
                JsAsset(it, hash, o.getString("href"))
            }.toList()
    }

    private fun parseCssAssets(describe: JSONObject): List<CssAsset> {
        val css = describe.optJSONArray("cssManifest") ?: JSONArray()
        return (0 until css.length()).map {
            val href = css.getJSONObject(it).getString("href")
            CssAsset(AssetCache.cssName(it, href), href)
        }
    }

    /** Joins a manifest href onto the server origin; absolute hrefs pass through. */
    private fun absUrl(origin: String, href: String): String =
        if (href.startsWith("http://") || href.startsWith("https://")) href else origin + href
}
