package gallery.memories.data.local.prefs

import gallery.memories.data.local.db.BucketDto
import gallery.memories.data.local.db.PhotoDao
import gallery.memories.data.local.media.MediaStoreDataSource
import gallery.memories.timeline.TimelineJson
import org.json.JSONArray
import org.json.JSONObject

/**
 * Device folders for the setup/timeline UI, derived live from indexed buckets.
 * Shape per entry: {id, name, enabled}; likely-camera folders first, then by size.
 * Must be touched off the main thread: the getter queries Room and MediaStore synchronously.
 */
class FolderSettings(
    private val dao: PhotoDao,
    private val dataSource: MediaStoreDataSource,
    private val prefs: PreferencesStore,
) {
    var localFolders: JSONArray
        get() = sorted(dao.getBuckets()).map {
            JSONObject()
                .put(TimelineJson.Bucket.ID, it.id)
                .put(TimelineJson.Bucket.NAME, it.name)
                .put(TimelineJson.Bucket.ENABLED, prefs.enabledBucketIds.contains(it.id))
        }.let { JSONArray(it) }
        set(value) {
            val enabled = mutableListOf<String>()
            for (i in 0 until value.length()) {
                val obj = value.getJSONObject(i)
                if (obj.getBoolean(TimelineJson.Bucket.ENABLED)) enabled.add(obj.getString(TimelineJson.Bucket.ID))
            }
            prefs.enabledBucketIds = enabled
        }

    /** Stable partition, so count order is kept within each group. */
    private fun sorted(buckets: List<BucketDto>): List<BucketDto> {
        val paths = try {
            dataSource.getByIds(buckets.map { it.sampleId }).associate { it.fileId to it.dataPath }
        } catch (_: Exception) {
            mapOf()
        }
        val (camera, other) = buckets.partition { isCamera(it.name, paths[it.sampleId] ?: "") }
        return camera + other
    }

    private fun isCamera(name: String, path: String): Boolean {
        if (CAMERA_NAMES.contains(name.lowercase())) return true
        val dir = path.substringBeforeLast('/').lowercase()
        return CAMERA_DIRS.any { dir.contains(it) } && !dir.contains("/.")
    }

    companion object {
        private val CAMERA_NAMES = setOf("camera", "opencamera")
        private val CAMERA_DIRS = listOf("/dcim", "/pictures/camera")
    }
}
