package gallery.memories.data.local.media

import android.content.Context
import android.provider.MediaStore
import android.util.Log
import gallery.memories.data.local.db.PhotoDao
import gallery.memories.data.local.prefs.PreferencesStore
import java.time.Instant

/**
 * Indexes device media into Room. Delta syncs cover files changed since
 * [PreferencesStore.lastSyncTime]; full syncs reindex everything and prune
 * missing files via the flag sweep. All methods run synchronously on the
 * caller thread and are re-entrancy-guarded via [syncStatus].
 */
class MediaSyncManager(
    private val ctx: Context,
    private val dao: PhotoDao,
    private val dataSource: MediaStoreDataSource,
    private val mapper: PhotoMapper,
    private val prefs: PreferencesStore,
) {
    companion object {
        private val TAG = MediaSyncManager::class.java.simpleName
    }

    /** Files indexed so far, or -1 when idle. Doubles as a re-entrancy guard. */
    @Volatile var syncStatus = -1
        private set

    /** Index only files changed since the last sync. Returns the indexed count. */
    fun syncDeltaDb(): Int {
        synchronized(this) {
            if (syncStatus != -1) return 0
            syncStatus = 0
        }
        return syncDb(prefs.lastSyncTime)
    }

    /** Reindex everything; files missing from the device are pruned via the flag sweep. */
    fun syncFullDb() {
        synchronized(this) {
            if (syncStatus != -1) return
            syncStatus = 0
        }
        dao.flagAll()
        syncDb(0L)
        dao.deleteFlagged()
    }

    private fun syncDb(startTime: Long): Int {
        val syncTime = Instant.now().toEpochMilli() / 1000
        var selection: String? = null
        var selectionArgs: Array<String>? = null
        if (startTime != 0L) {
            selection = MediaStore.Images.Media.DATE_MODIFIED + " > ?"
            selectionArgs = arrayOf(startTime.toString())
        }
        var updates = 0
        try {
            for (image in dataSource.cursor(SystemImage.IMAGE_URI, selection, selectionArgs, null)) {
                insertItem(image)
                updates++
                syncStatus = updates
            }
            for (video in dataSource.cursor(SystemImage.VIDEO_URI, selection, selectionArgs, null)) {
                insertItem(video)
                updates++
                syncStatus = updates
            }
            prefs.lastSyncTime = syncTime
        } catch (e: Exception) {
            Log.e(TAG, "Error syncing database", e)
        }
        synchronized(this) { syncStatus = -1 }
        return updates
    }

    /** Unchanged files (same mtime) are kept as-is; the rest are reinserted. */
    private fun insertItem(image: SystemImage) {
        val existing = dao.getPhotosByFileIds(listOf(image.fileId))
        if (existing.isNotEmpty() && existing[0].mtime == image.mtime) {
            dao.unflag(image.fileId)
            return
        }
        dao.deleteFileIds(listOf(image.fileId))
        dao.insert(mapper.toPhoto(image))
    }
}
