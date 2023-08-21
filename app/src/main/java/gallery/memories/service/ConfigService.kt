package gallery.memories.service

import android.content.Context
import gallery.memories.R

class ConfigService(private val mCtx: Context) {
    companion object {
        private var mEnabledBuckets: Set<String>? = null
    }

    var enabledBucketIds: Set<String>
        get() {
            if (mEnabledBuckets != null) return mEnabledBuckets!!
            mEnabledBuckets = mCtx.getSharedPreferences(mCtx.getString(R.string.preferences_key), 0)
                .getStringSet(mCtx.getString(R.string.preferences_enabled_local_folders), null) ?: setOf()
            return mEnabledBuckets!!
        }
        set(value) {
            mEnabledBuckets = value
            mCtx.getSharedPreferences(mCtx.getString(R.string.preferences_key), 0).edit()
                .putStringSet(mCtx.getString(R.string.preferences_enabled_local_folders), value)
                .apply()
        }
}