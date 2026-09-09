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
 */
class AssetSyncCoordinator(
    private val auth: AuthState,
    private val api: NextcloudApi,
    private val cache: AssetCache,
    private val downloader: AssetDownloader,
) {
    companion object {
        private val TAG = AssetSyncCoordinator::class.java.simpleName
        const val MAX_PARALLEL = 8
    }

    data class JsAsset(val name: String, val hash: String, val href: String)

    @Volatile var status = "idle"
    @Volatile var total = 0
    @Volatile var error: String? = null
    val done: Int get() = downloader.done

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

    fun fetchDescribe(): JSONObject? {
        return try {
            api.getApiDescriptionManifest().use { res ->
                if (res.code != 200) null else api.bodyJson(res)
            }
        } catch (_: Exception) {
            null
        }
    }

    fun isCurrent(fresh: JSONObject): Boolean {
        val base = fresh.optString("baseUrl", "")
        val version = fresh.optString("version", "")
        if (base.isEmpty() || version.isEmpty()) return false
        val dir = cache.dirFor(base, version)
        if (!File(dir, ".ready").exists()) return false
        val cur = cache.readDescribe(dir) ?: return false
        return cache.manifestKey(cur) == cache.manifestKey(fresh)
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
        if (snap != null && cur != null && cache.manifestKey(cur) == cache.manifestKey(fresh)) {
            return useSnapshot(snap)
        }
        if (!syncAndAwait(fresh, timeoutMs)) return false
        val version = fresh.getString("version")
        return useSnapshot(version to cache.dirFor(base, version))
    }

    private fun useSnapshot(snap: Pair<String, File>): Boolean {
        current = snap
        status = "ready"
        return true
    }

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
            val version = describe.getString("version")
            val baseUrl = describe.getString("baseUrl")
            val dir = cache.dirFor(baseUrl, version)
            val existing = cache.readDescribe(dir)?.takeIf { File(dir, ".ready").exists() }
            if (existing != null && cache.manifestKey(existing) == cache.manifestKey(describe)) {
                status = "ready"
                return true
            }
            syncBlocking(describe, baseUrl, dir)
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
        val jsB64 = describe.optString("jsManifest", "").ifEmpty { null } ?: throw Exception("Server has no asset manifest")
        val jsJson = JSONObject(String(Base64.decode(jsB64, Base64.DEFAULT)))
        val js = jsJson.keys().asSequence()
            .filter { it.endsWith(".js") || it.endsWith(".mjs") }
            .mapNotNull {
                val o = jsJson.getJSONObject(it)
                val hash = o.optString("hash", "").ifEmpty { null } ?: return@mapNotNull null
                JsAsset(it, hash, o.getString("href"))
            }.toList()
        val css = describe.optJSONArray("cssManifest") ?: JSONArray()
        total = js.size + css.length()
        downloader.reset()
        status = "downloading"
        error = null
        dir.deleteRecursively()
        val jsDir = File(dir, "js").apply { mkdirs() }
        val cssDir = File(dir, "css").apply { mkdirs() }

        data class Task(val dir: File, val name: String, val url: String, val hash: String?)
        val tasks = ArrayList<Task>(js.size + css.length())
        for (asset in js) {
            tasks.add(Task(jsDir, asset.name, absUrl(origin, asset.href), asset.hash))
        }
        for (i in 0 until css.length()) {
            val href = css.getJSONObject(i).getString("href")
            tasks.add(Task(cssDir, AssetCache.cssName(i, href), absUrl(origin, href), null))
        }

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
        Log.i(TAG, "Asset sync complete: ${downloader.done} files for $origin")
    }

    private fun absUrl(origin: String, href: String): String =
        if (href.startsWith("http://") || href.startsWith("https://")) href else origin + href
}
