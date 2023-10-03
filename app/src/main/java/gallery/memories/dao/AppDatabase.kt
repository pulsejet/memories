package gallery.memories.dao

import android.content.Context
import androidx.room.Database
import androidx.room.Room.databaseBuilder
import androidx.room.RoomDatabase
import androidx.sqlite.db.SupportSQLiteDatabase
import gallery.memories.R
import gallery.memories.mapper.Photo


@Database(entities = [Photo::class], version = 11)
abstract class AppDatabase : RoomDatabase() {
    abstract fun photoDao(): PhotoDao

    companion object {
        private val DATABASE_NAME = "memories_room"
        @Volatile
        private var INSTANCE: AppDatabase? = null

        fun get(context: Context): AppDatabase {
            if (INSTANCE == null) {
                synchronized(AppDatabase::class.java) {
                    val ctx = context.applicationContext
                    if (INSTANCE == null) {
                        INSTANCE = databaseBuilder(ctx, AppDatabase::class.java, DATABASE_NAME)
                            .fallbackToDestructiveMigration()
                            .addCallback(callbacks(ctx))
                            .build()
                    }
                }
            }
            return INSTANCE!!
        }

        private fun callbacks(ctx: Context): Callback {
            return object : Callback() {
                override fun onDestructiveMigration(db: SupportSQLiteDatabase) {
                    super.onDestructiveMigration(db)

                    // retrigger synchronization whenever database is destructed
                    ctx.getSharedPreferences(ctx.getString(R.string.preferences_key), 0).edit()
                        .remove(ctx.getString(R.string.preferences_last_sync_time))
                        .apply()
                }
            }
        }
    }
}