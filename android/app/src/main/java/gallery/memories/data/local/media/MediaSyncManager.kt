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
    private val isAlive: () -> Boolean,
) {
    companion object {
        private val TAG = MediaSyncManager::class.java.simpleName

        /** Process-wide: a reopened activity gets a new instance while the old one winds down. */
        private val RUN_LOCK = Any()
    }

    /** Files indexed so far, or -1 when idle. Doubles as a re-entrancy guard. */
    @Volatile var syncStatus = -1
        private set

    /** Index only files changed since the last sync. Returns the indexed count. */
    fun syncDeltaDb(): Int = exclusive(0) { syncDb(prefs.lastSyncTime) }

    /** Reindex everything; files missing from the device are pruned via the flag sweep. */
    fun syncFullDb(): Unit = exclusive(Unit) {
        try {
            dao.flagAll()
            syncDb(0L, onComplete = dao::deleteFlagged)
        } catch (e: Exception) {
            Log.e(TAG, "Error starting full sync", e)
        }
    }

    /** Evicts index rows whose device files are gone. Returns the pruned count. */
    fun pruneDeleted(): Int = exclusive(0) {
        try {
            var deleted = 0
            for (chunk in dao.getAllLocalIds().chunked(500)) {
                if (!isAlive()) break
                val found = dataSource.getByIds(chunk).map { it.fileId }.toSet()
                if (found.size == chunk.size) continue
                val missing = chunk.filter { it !in found }
                if (missing.isNotEmpty()) {
                    dao.deleteFileIds(missing)
                    deleted += missing.size
                }
            }
            deleted
        } catch (e: Exception) {
            Log.e(TAG, "Error pruning database", e)
            0
        }
    }

    /** Skips [block] when this instance is busy; otherwise queues behind any other instance's run. */
    private inline fun <T> exclusive(busy: T, block: () -> T): T {
        synchronized(this) {
            if (syncStatus != -1) return busy
            syncStatus = 0
        }
        try {
            return synchronized(RUN_LOCK) { block() }
        } finally {
            synchronized(this) { syncStatus = -1 }
        }
    }

    /** Runs [onComplete] only if every row was read; a failed or abandoned sync reports what it indexed. */
    private fun syncDb(startTime: Long, onComplete: () -> Unit = {}): Int {
        val syncTime = Instant.now().toEpochMilli() / 1000
        var selection: String? = null
        var selectionArgs: Array<String>? = null
        if (startTime != 0L) {
            selection = MediaStore.Images.Media.DATE_MODIFIED + " > ?"
            selectionArgs = arrayOf(startTime.toString())
        }
        var updates = 0
        try {
            for (collection in listOf(SystemImage.IMAGE_URI, SystemImage.VIDEO_URI)) {
                for (item in dataSource.cursor(collection, selection, selectionArgs, null, isAlive)) {
                    insertItem(item)
                    updates++
                    syncStatus = updates
                }
            }
            if (!isAlive()) return updates
            onComplete()
            prefs.lastSyncTime = syncTime
        } catch (e: Exception) {
            Log.e(TAG, "Error syncing database", e)
        }
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
