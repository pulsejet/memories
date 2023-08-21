package gallery.memories.mapper

import android.database.Cursor
import org.json.JSONObject

class Day {
    val dayId: Long
    val count: Long

    companion object {
        val FIELDS get(): Array<String> {
            return arrayOf(
                Photo.FIELD_DAY_ID,
                "COUNT(${Photo.FIELD_LOCAL_ID})"
            )
        }

        fun unpack(cursor: Cursor): List<Day> {
            val days = mutableListOf<Day>()
            while (cursor.moveToNext()) {
                days.add(Day(cursor))
            }
            return days
        }
    }

    constructor(cursor: Cursor) {
        dayId = cursor.getLong(0)
        count = cursor.getLong(1)
    }

    val json get(): JSONObject {
        return JSONObject()
            .put(Fields.Day.DAYID, dayId)
            .put(Fields.Day.COUNT, count)
    }
}