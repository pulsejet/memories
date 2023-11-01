package gallery.memories.mapper

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(
    tableName = "photos", indices = [
        Index(value = ["local_id"]),
        Index(value = ["auid"]),
        Index(value = ["buid"]),
        Index(value = ["dayid"]),
        Index(value = ["flag"]),
        Index(value = ["bucket_id"]),
        Index(value = ["bucket_id", "dayid", "has_remote"])
    ]
)
data class Photo(
    @PrimaryKey(autoGenerate = true) val id: Int? = null,
    @ColumnInfo(name = "local_id") val localId: Long,
    @ColumnInfo(name = "auid") val auid: String,
    @ColumnInfo(name = "buid") val buid: String,
    @ColumnInfo(name = "mtime") val mtime: Long,
    @ColumnInfo(name = "date_taken") val dateTaken: Long,
    @ColumnInfo(name = "dayid") val dayId: Long,
    @ColumnInfo(name = "basename") val baseName: String,
    @ColumnInfo(name = "bucket_id") val bucketId: Long,
    @ColumnInfo(name = "bucket_name") val bucketName: String,
    @ColumnInfo(name = "has_remote") val hasRemote: Boolean,
    @ColumnInfo(name = "flag") val flag: Int
)