package gallery.memories.data.remote.assets

import android.content.Context
import androidx.core.net.toUri
import io.github.g00fy2.versioncompare.Version
import org.json.JSONObject
import java.io.File

/** On-disk snapshots of the web app (one dir per server+version). A snapshot is usable only with a .ready marker. */
class AssetCache(private val ctx: Context) {
    companion object {
        const val ENTRY_JS = "memories-main.js"

        fun cssName(i: Int, href: String): String =
            "%02d_%s".format(i, href.substringAfterLast("/").substringBefore("?"))

        fun originOf(baseUrl: String): String {
            val uri = baseUrl.toUri()
            val port = uri.port.takeIf { it > 0 }?.let { ":$it" } ?: ""
            return "${uri.scheme}://${uri.host}$port"
        }

        fun webrootOf(baseUrl: String): String {
            val path = baseUrl.toUri().path ?: ""
            return path.substringBefore("/index.php/")
        }
    }

    fun dirFor(baseUrl: String, version: String): File = File(serverDir(baseUrl), version)

    fun serverDir(baseUrl: String): File {
        val uri = baseUrl.toUri()
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

    /** Version-independent content key: catches upstream rebuilds that keep the version number. */
    fun manifestKey(describe: JSONObject): String =
        describe.optString("jsManifest", "") + "\n" + describe.opt("cssManifest")
}
