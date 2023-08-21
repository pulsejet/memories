package gallery.memories.mapper

import androidx.room.ColumnInfo

data class Day (
    @ColumnInfo(name="dayid") val dayId: Long,
    @ColumnInfo(name="count") val count: Long
)