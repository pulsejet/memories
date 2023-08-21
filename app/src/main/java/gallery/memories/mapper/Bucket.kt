package gallery.memories.mapper

import androidx.room.ColumnInfo

data class Bucket(
    @ColumnInfo(name = "bucket_id") val id: String,
    @ColumnInfo(name = "bucket_name") val name: String,
)