package gallery.memories.data.local.db

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.sqlite.db.SupportSQLiteDatabase
import gallery.memories.data.local.prefs.PreferencesStore

@Database(entities = [PhotoEntity::class], version = 34, exportSchema = false)
abstract class AppDatabase : RoomDatabase() {
    abstract fun photoDao(): PhotoDao

    companion object {
        private const val DATABASE_NAME = "memories_room"

        @Volatile
        private var INSTANCE: AppDatabase? = null

        fun get(context: Context): AppDatabase {
            return INSTANCE ?: synchronized(AppDatabase::class.java) {
                val ctx = context.applicationContext
                INSTANCE ?: Room.databaseBuilder(ctx, AppDatabase::class.java, DATABASE_NAME)
                    .fallbackToDestructiveMigration()
                    .addCallback(callbacks(ctx))
                    .build()
                    .also { INSTANCE = it }
            }
        }

        /** A wiped DB must force a full resync, otherwise stale sync time hides all files. */
        private fun callbacks(ctx: Context): Callback {
            return object : Callback() {
                override fun onDestructiveMigration(db: SupportSQLiteDatabase) {
                    super.onDestructiveMigration(db)
                    PreferencesStore(ctx).clearSyncTime()
                }
            }
        }
    }
}
