package gallery.memories.data.local.prefs

import gallery.memories.data.local.db.PhotoDao
import gallery.memories.timeline.TimelineJson
import org.json.JSONArray
import org.json.JSONObject

/**
 * Device folders for the setup/timeline UI, derived live from indexed buckets.
 * Shape per entry: {id, name, enabled}. Must be touched off the main thread:
 * the getter queries Room synchronously.
 */
class FolderSettings(
    private val dao: PhotoDao,
    private val prefs: PreferencesStore,
) {
    var localFolders: JSONArray
        get() = dao.getBuckets().map {
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
}
