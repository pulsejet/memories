package gallery.memories.data.local.db

import androidx.room.ColumnInfo

/** A device media folder (MediaStore bucket) known to the DB. */
data class BucketDto(
    @ColumnInfo(name = "bucket_id") val id: String,
    @ColumnInfo(name = "bucket_name") val name: String,
)
