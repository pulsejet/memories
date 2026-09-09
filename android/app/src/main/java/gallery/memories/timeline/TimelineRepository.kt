package gallery.memories.timeline

import gallery.memories.data.local.media.SystemImage
import org.json.JSONArray
import org.json.JSONObject

/** Local-media timeline backing the web UI. Shapes must match the server API the frontend expects. */
interface TimelineRepository {
    val syncStatus: Int
    fun initialize()
    fun destroy()
    fun syncDeltaDb(): Int
    fun syncFullDb()
    fun getSystemImagesByAUIDs(auids: List<String>): List<SystemImage>
    fun getDays(): JSONArray
    fun getDay(dayId: Long): JSONArray
    fun getImageInfo(id: Long): JSONObject
    fun delete(auids: List<String>, dry: Boolean): JSONObject
    fun setHasRemote(auids: List<String>, buids: List<String>, value: Boolean)
    var localFolders: JSONArray
}
