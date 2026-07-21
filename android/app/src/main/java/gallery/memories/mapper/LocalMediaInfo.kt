package gallery.memories.mapper

import android.net.Uri

data class LocalMediaInfo(
    val localId: Long,     // MediaStore _ID
    val auid: String?,     // null if not yet synced to Room DB
    val dayId: Long?,      // null if not in Room DB
    val mimeType: String,
    val uri: Uri,
    val isVideo: Boolean
)
