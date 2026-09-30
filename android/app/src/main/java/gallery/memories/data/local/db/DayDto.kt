package gallery.memories.data.local.db

import androidx.room.ColumnInfo

/** One timeline day: epoch-day id plus local photo count. */
data class DayDto(
    @ColumnInfo(name = "dayid") val dayId: Long,
    @ColumnInfo(name = "count") val count: Long,
)
