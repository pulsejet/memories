package gallery.memories.data.local.db

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query

@Dao
interface PhotoDao {
    @Query("SELECT 1")
    fun ping(): Int

    /** Local-only timeline: files already on the server (has_remote) are excluded. */
    @Query("SELECT dayid, COUNT(local_id) AS count FROM photos WHERE bucket_id IN (:bucketIds) AND has_remote = 0 GROUP BY dayid ORDER BY dayid DESC")
    fun getDays(bucketIds: List<String>): List<DayDto>

    @Query("SELECT * FROM photos WHERE dayid=:dayId AND bucket_id IN (:buckets) AND has_remote = 0 ORDER BY date_taken DESC")
    fun getPhotosByDay(dayId: Long, buckets: List<String>): List<PhotoEntity>

    @Query("DELETE FROM photos WHERE local_id IN (:fileIds)")
    fun deleteFileIds(fileIds: List<Long>)

    @Query("SELECT * FROM photos WHERE local_id IN (:fileIds)")
    fun getPhotosByFileIds(fileIds: List<Long>): List<PhotoEntity>

    @Query("SELECT * FROM photos WHERE auid IN (:auids)")
    fun getPhotosByAUIDs(auids: List<String>): List<PhotoEntity>

    @Query("UPDATE photos SET flag=1")
    fun flagAll()

    @Query("UPDATE photos SET flag=0 WHERE local_id=:fileId")
    fun unflag(fileId: Long)

    /** Full-sync sweep: everything flagged (i.e. unseen) afterwards is gone from the device. */
    @Query("DELETE FROM photos WHERE flag=1")
    fun deleteFlagged()

    @Insert
    fun insert(vararg photos: PhotoEntity)

    @Query("SELECT bucket_id, bucket_name FROM photos GROUP BY bucket_id")
    fun getBuckets(): List<BucketDto>

    @Query("UPDATE photos SET has_remote=:v WHERE auid IN (:auids) OR buid IN (:buids)")
    fun setHasRemote(auids: List<String>, buids: List<String>, v: Boolean)

    @Query("UPDATE photos SET has_remote=:v WHERE auid IN (:auids)")
    fun setHasRemoteByAuids(auids: List<String>, v: Boolean)

    @Query("UPDATE photos SET has_remote=:v WHERE buid IN (:buids)")
    fun setHasRemoteByBuids(buids: List<String>, v: Boolean)
}
