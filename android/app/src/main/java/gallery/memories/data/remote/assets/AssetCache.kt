package gallery.memories.data.remote.assets

import android.content.Context
import android.net.Uri
import io.github.g00fy2.versioncompare.Version
import org.json.JSONObject
import java.io.File

/** On-disk snapshots of the web app (one dir per server+version). A snapshot is usable only with a .ready marker. */
class AssetCache(private val ctx: Context) {
    companion object {
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

    /** Snapshot dir for one server version. */
    fun dirFor(baseUrl: String, version: String): File = File(serverDir(baseUrl), version)

    /** Root dir for one server; the host is sanitized so it is a safe path segment. */
    fun serverDir(baseUrl: String): File {
        val uri = Uri.parse(baseUrl)
        val port = uri.port.takeIf { it > 0 }?.toString() ?: ""
        val safe = "${uri.scheme}_${uri.host}$port".replace(Regex("[^A-Za-z0-9_.-]"), "_")
        return File(ctx.applicationContext.filesDir, "offline/$safe")
    }

    /** Newest usable snapshot; version comparison tolerates partial downloads via .ready. */
    fun latestSnapshot(baseUrl: String): Pair<String, File>? {
        return try {
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

    /** Version-independent content key: catches upstream rebuilds that keep the version number. */
    fun manifestKey(describe: JSONObject): String =
        describe.optString("jsManifest", "") + "\n" + describe.opt("cssManifest")
}
