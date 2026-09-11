package gallery.memories.data.local.db

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query

@Dao
/** Device-media index. Web merges local and remote timelines; use [hasRemote] for that. */
interface PhotoDao {
    /** Cheap liveness probe used to fail fast when the DB was wiped. */
    @Query("SELECT 1")
    fun ping(): Int

    /** All local days; backed-up files are flagged via has_remote but included. */
    @Query("SELECT dayid, COUNT(local_id) AS count FROM photos WHERE bucket_id IN (:bucketIds) GROUP BY dayid ORDER BY dayid DESC")
    fun getDays(bucketIds: List<String>): List<DayDto>

    /** One day of local photos, newest first, including files already on the server. */
    @Query("SELECT * FROM photos WHERE dayid=:dayId AND bucket_id IN (:bucketIds) ORDER BY date_taken DESC")
    fun getPhotosByDay(dayId: Long, bucketIds: List<String>): List<PhotoEntity>

    /** Drops index rows for device files, e.g. after deletion or reindexing. */
    @Query("DELETE FROM photos WHERE local_id IN (:fileIds)")
    fun deleteFileIds(fileIds: List<Long>)

    @Query("SELECT * FROM photos WHERE local_id IN (:fileIds)")
    fun getPhotosByFileIds(fileIds: List<Long>): List<PhotoEntity>

    @Query("SELECT * FROM photos WHERE auid IN (:auids)")
    fun getPhotosByAUIDs(auids: List<String>): List<PhotoEntity>

    /** Full-sync sweep start: flags every row; seen rows are unflagged during reindex. */
    @Query("UPDATE photos SET flag=1")
    fun flagAll()

    /** Marks one file as still present during the full-sync sweep. */
    @Query("UPDATE photos SET flag=0 WHERE local_id=:fileId")
    fun unflag(fileId: Long)

    /** Full-sync sweep: everything flagged (i.e. unseen) afterwards is gone from the device. */
    @Query("DELETE FROM photos WHERE flag=1")
    fun deleteFlagged()

    @Insert
    fun insert(vararg photos: PhotoEntity)

    /** All media folders known to the index, for the folder picker. */
    @Query("SELECT bucket_id, bucket_name FROM photos GROUP BY bucket_id")
    fun getBuckets(): List<BucketDto>

    /**
     * Marks indexed files as already on the server. Matches when either id
     * hits; callers with only one kind must use the ByAuids/ByBuids variants
     * to avoid over-matching on an empty list.
     */
    @Query("UPDATE photos SET has_remote=:v WHERE auid IN (:auids) OR buid IN (:buids)")
    fun setHasRemote(auids: List<String>, buids: List<String>, v: Boolean)

    @Query("UPDATE photos SET has_remote=:v WHERE auid IN (:auids)")
    fun setHasRemoteByAuids(auids: List<String>, v: Boolean)

    @Query("UPDATE photos SET has_remote=:v WHERE buid IN (:buids)")
    fun setHasRemoteByBuids(buids: List<String>, v: Boolean)
}
