package gallery.memories.data.local.db

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/** Mirror of a device media file. auid/buid are content-derived IDs shared with the server; flag drives the full-sync sweep. */
@Entity(
    tableName = "photos",
    indices = [
        Index(value = ["local_id"]),
        Index(value = ["auid"]),
        Index(value = ["buid"]),
        Index(value = ["dayid"]),
        Index(value = ["flag"]),
        Index(value = ["bucket_id"]),
        Index(value = ["bucket_id", "dayid", "has_remote"]),
    ],
)
data class PhotoEntity(
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
    /** Whether the file is already on the server; such rows are hidden from the local timeline. */
    @ColumnInfo(name = "has_remote") val hasRemote: Boolean,
    /** Full-sync sweep marker: 1 = unseen this sweep (delete candidate), 0 = seen. */
    @ColumnInfo(name = "flag") val flag: Int,
)
