package gallery.memories.dao

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query
import gallery.memories.mapper.Bucket
import gallery.memories.mapper.Day
import gallery.memories.mapper.Photo

@Dao
interface PhotoDao {
    @Query("SELECT 1")
    fun ping(): Int

    @Query("SELECT dayid, COUNT(local_id) AS count FROM photos WHERE bucket_id IN (:bucketIds) AND has_remote = 0 GROUP BY dayid ORDER BY dayid DESC")
    fun getDays(bucketIds: List<String>): List<Day>

    @Query("SELECT * FROM photos WHERE dayid=:dayId AND bucket_id IN (:buckets) AND has_remote = 0 ORDER BY date_taken DESC")
    fun getPhotosByDay(dayId: Long, buckets: List<String>): List<Photo>

    @Query("DELETE FROM photos WHERE local_id IN (:fileIds)")
    fun deleteFileIds(fileIds: List<Long>)

    @Query("SELECT * FROM photos WHERE local_id IN (:fileIds)")
    fun getPhotosByFileIds(fileIds: List<Long>): List<Photo>

    @Query("SELECT * FROM photos WHERE auid IN (:auids)")
    fun getPhotosByAUIDs(auids: List<String>): List<Photo>

    @Query("UPDATE photos SET flag=1")
    fun flagAll()

    @Query("UPDATE photos SET flag=0 WHERE local_id=:fileId")
    fun unflag(fileId: Long)

    @Query("DELETE FROM photos WHERE flag=1")
    fun deleteFlagged()

    @Insert
    fun insert(vararg photos: Photo)

    @Query("SELECT bucket_id, bucket_name FROM photos GROUP BY bucket_id")
    fun getBuckets(): List<Bucket>

    @Query("UPDATE photos SET has_remote=:v WHERE auid IN (:auids) OR buid IN (:buids)")
    fun setHasRemote(auids: List<String>, buids: List<String>, v: Boolean)
}