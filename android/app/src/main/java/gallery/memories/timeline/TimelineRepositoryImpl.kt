package gallery.memories.timeline

import android.os.Build
import android.util.Log
import androidx.media3.common.util.UnstableApi
import gallery.memories.data.local.db.PhotoDao
import gallery.memories.data.local.media.MediaDeleter
import gallery.memories.data.local.media.MediaObserver
import gallery.memories.data.local.media.MediaStoreDataSource
import gallery.memories.data.local.media.MediaSyncManager
import gallery.memories.data.local.media.PhotoMapper
import gallery.memories.data.local.media.SystemImage
import gallery.memories.data.local.prefs.FolderSettings
import gallery.memories.data.local.prefs.PreferencesStore
import org.json.JSONArray
import org.json.JSONObject

@UnstableApi
/** Local-media timeline: Room index joined with MediaStore rows, shaped as server-API JSON. */
class TimelineRepositoryImpl(
    private val dao: PhotoDao,
    private val dataSource: MediaStoreDataSource,
    private val mapper: PhotoMapper,
    private val prefs: PreferencesStore,
    private val syncManager: MediaSyncManager,
    private val deleter: MediaDeleter,
    private val folders: FolderSettings,
    private val observer: MediaObserver,
    private val onDatabaseChanged: () -> Unit,
    private val isAlive: () -> Boolean,
) : TimelineRepository {
    companion object {
        private val TAG = TimelineRepositoryImpl::class.java.simpleName
    }

    override val syncStatus: Int get() = syncManager.syncStatus

    override fun initialize() {
        dao.ping()
        if (syncManager.syncDeltaDb() > 0) onDatabaseChanged()
        observer.register()
    }

    override fun destroy() = observer.unregister()

    override fun syncDeltaDb(): Int = syncManager.syncDeltaDb()

    override fun syncFullDb() = syncManager.syncFullDb()

    override fun getSystemImagesByAUIDs(auids: List<String>): List<SystemImage> {
        val photos = dao.getPhotosByAUIDs(auids)
        if (photos.isEmpty()) return listOf()
        return dataSource.getByIds(photos.map { it.localId })
    }

    override fun getDays(): JSONArray {
        val buckets = prefs.enabledBucketIds
        if (buckets.isEmpty()) return JSONArray()
        return dao.getDays(buckets).map {
            JSONObject().put(TimelineJson.Day.DAYID, it.dayId).put(TimelineJson.Day.COUNT, it.count)
        }.let { JSONArray(it) }
    }

    /**
     * One day of photos. Prunes index rows whose device files vanished
     * outside the app as a side effect.
     */
    override fun getDay(dayId: Long): JSONArray {
        val buckets = prefs.enabledBucketIds
        if (buckets.isEmpty()) return JSONArray()
        val photos = dao.getPhotosByDay(dayId, buckets).associateBy { it.localId }
        if (photos.isEmpty()) return JSONArray()
        val fileIds = photos.keys.toMutableList()
        val response = dataSource.getByIds(fileIds.toList()).map { image ->
            fileIds.remove(image.fileId)
            val json = mapper.toJson(image)
            photos[image.fileId]?.let { photo ->
                json.put(TimelineJson.Photo.AUID, photo.auid)
                    .put(TimelineJson.Photo.BUID, photo.buid)
                    .put(TimelineJson.Photo.LOCAL_HAS_REMOTE, photo.hasRemote)
                    .put(TimelineJson.Photo.DAYID, dayId)
            }
            json
        }.let { JSONArray(it) }
        // Anything left was deleted from the device outside the app.
        dao.deleteFileIds(fileIds)
        return response
    }

    override fun getImageInfo(id: Long): JSONObject {
        val photos = dao.getPhotosByFileIds(listOf(id))
        if (photos.isEmpty()) throw Exception("File not found in database")
        val images = dataSource.getByIds(listOf(id))
        if (images.isEmpty()) throw Exception("File not found in system")
        val photo = photos[0]
        val image = images[0]
        val obj = mapper.toJson(image)
            .put(TimelineJson.Photo.DAYID, photo.dayId)
            .put(TimelineJson.Photo.DATETAKEN, photo.dateTaken)
            .put(TimelineJson.Photo.PERMISSIONS, TimelineJson.Perm.DELETE)
        try {
            val exif = mapper.exifOf(image)
            obj.put(TimelineJson.Photo.EXIF, JSONObject().apply {
                if (exif != null) {
                    TimelineJson.EXIF.MAP.forEach { (key, field) -> put(field, exif.getAttribute(key)) }
                }
                // Decimal degrees to match the server; raw EXIF GPS is DMS rationals.
                try {
                    mapper.latLong(image, exif)?.let { ll ->
                        put("GPSLatitude", ll[0])
                        put("GPSLongitude", ll[1])
                    }
                } catch (e: Exception) {
                    Log.w(TAG, "Error reading location for $id: ${e.message}")
                }
            })
        } catch (e: Exception) {
            Log.w(TAG, "Error reading EXIF data for $id")
        }
        return obj
    }

    /**
     * Deletes device files. dry only counts; confirms reports whether the OS
     * will ask the user (R+) versus deleting silently.
     */
    override fun delete(auids: List<String>, dry: Boolean): JSONObject {
        val response = JSONObject().put("message", "ok")
        val sysImgs = getSystemImagesByAUIDs(auids)
        response.put("count", sysImgs.size)
        response.put("confirms", Build.VERSION.SDK_INT >= Build.VERSION_CODES.R)
        if (dry || sysImgs.isEmpty()) return response
        deleter.deleteUris(sysImgs.map { it.uri })
        dao.deleteFileIds(sysImgs.map { it.fileId })
        onDatabaseChanged()
        return response
    }

    /** Records which indexed files are already on the server; web filters on it. */
    override fun setHasRemote(auids: List<String>, buids: List<String>, value: Boolean) {
        if (auids.isEmpty()) {
            if (buids.isEmpty()) return
            dao.setHasRemoteByBuids(buids, value)
        } else if (buids.isEmpty()) {
            dao.setHasRemoteByAuids(auids, value)
        } else {
            dao.setHasRemote(auids, buids, value)
        }
    }

    override var localFolders: JSONArray
        get() = folders.localFolders
        set(value) { folders.localFolders = value }
}
