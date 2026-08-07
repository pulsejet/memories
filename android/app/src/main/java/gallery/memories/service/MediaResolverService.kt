package gallery.memories.service

import android.content.ContentResolver
import android.content.Context
import android.net.Uri
import android.os.Build
import android.provider.MediaStore
import androidx.core.content.ContentUriCompat
import androidx.core.database.getIntOrNull
import androidx.core.database.getLongOrNull
import androidx.core.database.getStringOrNull
import gallery.memories.dao.AppDatabase
import gallery.memories.mapper.LocalMediaInfo

class MediaResolverService(private val context: Context) {
    private val contentResolver: ContentResolver = context.contentResolver
    private val database: AppDatabase = AppDatabase.getInstance(context)

    /**
     * Resolves any incoming content:// URI to a LocalMediaInfo
     *
     * Algorithm:
     * 1. Query MediaStore (both Images.Media and Videos.Media) with the URI to get _ID, MIME_TYPE
     * 2. Use ContentUris.parseId(uri) as a fast path for canonical MediaStore URIs
     * 3. Look up _ID in Room DB via photoDao.getPhotosByFileIds(listOf(localId)) to get auid, dayId
     * 4. Return LocalMediaInfo with auid/dayId as null if not in DB
     */
    fun resolveUri(uri: Uri): LocalMediaInfo? {
        // Try to extract the local ID from the URI directly
        val localId = try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                ContentUriCompat.getUriId(uri)
            } else {
                // Fallback for older versions
                uri.lastPathSegment?.toLongOrNull()
            }
        } catch (e: Exception) {
            null
        }

        if (localId != null) {
            // Try to query MediaStore with this ID
            val mediaInfo = queryMediaStore(localId)
            if (mediaInfo != null) {
                // Look up in Room DB
                val photoList = database.photoDao().getPhotosByFileIds(listOf(localId))
                val photo = photoList.firstOrNull()

                return LocalMediaInfo(
                    localId = localId,
                    auid = photo?.auid,
                    dayId = photo?.dayId,
                    mimeType = mediaInfo.mimeType,
                    uri = uri,
                    isVideo = mediaInfo.isVideo
                )
            }
        }

        // Fallback: query both Images and Videos collections
        // Try Images first
        val imageProjection = arrayOf(
            MediaStore.Images.Media._ID,
            MediaStore.Images.Media.MIME_TYPE
        )
        contentResolver.query(
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            imageProjection,
            "${MediaStore.Images.Media.DATA} = ?",
            arrayOf(uri.path),
            null
        )?.use { cursor ->
            if (cursor.moveToFirst()) {
                val id = cursor.getLongOrNull(0) ?: return@use
                val mimeType = cursor.getStringOrNull(1) ?: "image/*"

                val photoList = database.photoDao().getPhotosByFileIds(listOf(id))
                val photo = photoList.firstOrNull()

                return LocalMediaInfo(
                    localId = id,
                    auid = photo?.auid,
                    dayId = photo?.dayId,
                    mimeType = mimeType,
                    uri = uri,
                    isVideo = false
                )
            }
        }

        // Try Videos
        val videoProjection = arrayOf(
            MediaStore.Video.Media._ID,
            MediaStore.Video.Media.MIME_TYPE
        )
        contentResolver.query(
            MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
            videoProjection,
            "${MediaStore.Video.Media.DATA} = ?",
            arrayOf(uri.path),
            null
        )?.use { cursor ->
            if (cursor.moveToFirst()) {
                val id = cursor.getLongOrNull(0) ?: return@use
                val mimeType = cursor.getStringOrNull(1) ?: "video/*"

                val photoList = database.photoDao().getPhotosByFileIds(listOf(id))
                val photo = photoList.firstOrNull()

                return LocalMediaInfo(
                    localId = id,
                    auid = photo?.auid,
                    dayId = photo?.dayId,
                    mimeType = mimeType,
                    uri = uri,
                    isVideo = true
                )
            }
        }

        return null
    }

    private fun queryMediaStore(localId: Long): MediaStoreInfo? {
        // Try Images first
        val imageProjection = arrayOf(
            MediaStore.Images.Media.MIME_TYPE
        )
        contentResolver.query(
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
            imageProjection,
            "${MediaStore.Images.Media._ID} = ?",
            arrayOf(localId.toString()),
            null
        )?.use { cursor ->
            if (cursor.moveToFirst()) {
                val mimeType = cursor.getStringOrNull(0) ?: "image/*"
                return MediaStoreInfo(mimeType, false)
            }
        }

        // Try Videos
        val videoProjection = arrayOf(
            MediaStore.Video.Media.MIME_TYPE
        )
        contentResolver.query(
            MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
            videoProjection,
            "${MediaStore.Video.Media._ID} = ?",
            arrayOf(localId.toString()),
            null
        )?.use { cursor ->
            if (cursor.moveToFirst()) {
                val mimeType = cursor.getStringOrNull(0) ?: "video/*"
                return MediaStoreInfo(mimeType, true)
            }
        }

        return null
    }

    private data class MediaStoreInfo(val mimeType: String, val isVideo: Boolean)
}
