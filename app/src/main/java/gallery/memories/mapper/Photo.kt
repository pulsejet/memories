package gallery.memories.mapper

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName="photos")
data class Photo (
    @PrimaryKey(autoGenerate = true) val id: Int? = null,
    @ColumnInfo(name="local_id") val localId: Long,
    @ColumnInfo(name="auid") val auid: Long,
    @ColumnInfo(name="mtime") val mtime: Long,
    @ColumnInfo(name="date_taken") val dateTaken: Long,
    @ColumnInfo(name="dayid") val dayId: Long,
    @ColumnInfo(name="basename") val baseName: String,
    @ColumnInfo(name="bucket_id") val bucketId: Long,
    @ColumnInfo(name="bucket_name") val bucketName: String,
    @ColumnInfo(name="flag") val flag: Int
)