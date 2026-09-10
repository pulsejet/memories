package gallery.memories.data.remote.assets

import android.content.Context
import android.net.Uri
import android.util.Log
import org.json.JSONObject
import java.io.File
import java.security.MessageDigest

/**
 * On-disk snapshots of the web app (one dir per server + manifest hash).
 * A snapshot is usable only with a .ready marker. Dirs are content-addressed:
 * the name is the SHA-256 of the manifests, so each distinct asset set gets
 * its own dir regardless of the server version string.
 */
class AssetCache(private val ctx: Context) {
    companion object {
        private val TAG = AssetCache::class.java.simpleName
        /** Entry chunk the shell page loads; every snapshot must contain it. */
        const val ENTRY_JS = "memories-main.js"

        /** Stable local filename for the i-th stylesheet: index plus remote basename. */
        fun cssName(i: Int, href: String): String =
            "%02d_%s".format(i, href.substringAfterLast("/").substringBefore("?"))

        /** Scheme + host + port of a describeApi baseUrl, e.g. https://cloud.example.com. */
        fun originOf(baseUrl: String): String {
            val uri = Uri.parse(baseUrl)
            val port = uri.port.takeIf { it > 0 }?.let { ":$it" } ?: ""
            return "${uri.scheme}://${uri.host}$port"
        }

        /** Nextcloud web root (path prefix before index.php), "" for domain roots. */
        fun webrootOf(baseUrl: String): String {
            val path = Uri.parse(baseUrl).path ?: ""
            return path.substringBefore("/index.php/")
        }
    }

    /** Snapshot dir for one manifest [key] (see [snapshotKey]). */
    fun dirFor(baseUrl: String, key: String): File = File(serverDir(baseUrl), key)

    /** Root dir for one server; the host is sanitized so it is a safe path segment. */
    fun serverDir(baseUrl: String): File {
        val uri = Uri.parse(baseUrl)
        val port = uri.port.takeIf { it > 0 }?.toString() ?: ""
        val safe = "${uri.scheme}_${uri.host}$port".replace(Regex("[^A-Za-z0-9_.-]"), "_")
        return File(ctx.applicationContext.filesDir, "offline/$safe")
    }

    /** Newest usable snapshot by modification time; usable means a .ready marker exists. */
    fun latestSnapshot(baseUrl: String): Pair<String, File>? {
        return try {
            val root = serverDir(baseUrl)
            if (!root.isDirectory) return null
            root.listFiles()
                ?.filter { it.isDirectory && File(it, ".ready").exists() }
                ?.maxByOrNull { it.lastModified() }
                ?.let { it.name to it }
        } catch (_: Exception) {
            null
        }
    }

    /** Stored describeApi of a snapshot dir, null when missing or corrupt. */
    fun readDescribe(dir: File): JSONObject? {
        return try {
            JSONObject(File(dir, "describe.json").readText())
        } catch (_: Exception) {
            null
        }
    }

    /** Whether any usable snapshot exists for [baseUrl]. */
    fun hasSnapshot(baseUrl: String): Boolean {
        return try {
            latestSnapshot(baseUrl) != null
        } catch (_: Exception) {
            false
        }
    }

    /**
     * Deletes every snapshot for [baseUrl] except [keepKey].
     * Best-effort: failures are logged, never thrown, so pruning can't
     * break a sync or boot. Also sweeps legacy version-named dirs, whose
     * names never match a manifest hash.
     */
    fun pruneExcept(baseUrl: String, keepKey: String) {
        try {
            serverDir(baseUrl).listFiles()
                ?.filter { it.isDirectory && it.name != keepKey }
                ?.forEach { dir ->
                    if (dir.deleteRecursively()) {
                        Log.i(TAG, "Pruned old snapshot: ${dir.name}")
                    } else {
                        Log.w(TAG, "Failed to prune old snapshot: ${dir.name}")
                    }
                }
        } catch (e: Exception) {
            Log.w(TAG, "Snapshot prune failed", e)
        }
    }

    /** Version-independent content key: catches upstream rebuilds that keep the version number. */
    fun manifestKey(describe: JSONObject): String =
        describe.optString("jsManifest", "") + "\n" + describe.opt("cssManifest")

    /** Content address of a describe: SHA-256 of its [manifestKey], used as the snapshot dir name. */
    fun snapshotKey(describe: JSONObject): String =
        MessageDigest.getInstance("SHA-256")
            .digest(manifestKey(describe).toByteArray())
            .joinToString("") { "%02x".format(it) }
}
