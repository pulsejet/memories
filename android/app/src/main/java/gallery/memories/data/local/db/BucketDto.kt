package gallery.memories.data.local.db

import androidx.room.ColumnInfo

/**
 * A device media folder (MediaStore bucket) known to the DB. [id] is String because Room reads the numeric bucket_id column as text.
 * [sampleId] is any file in the folder, used to resolve its path.
 */
data class BucketDto(
    @ColumnInfo(name = "bucket_id") val id: String,
    @ColumnInfo(name = "bucket_name") val name: String,
    @ColumnInfo(name = "sample_id") val sampleId: Long,
    @ColumnInfo(name = "count") val count: Int,
)
