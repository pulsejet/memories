package gallery.memories.widget

import SecureStorage
import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.util.Base64
import android.util.Log
import android.view.View
import android.widget.RemoteViews
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.bumptech.glide.Glide
import com.bumptech.glide.load.DataSource
import com.bumptech.glide.load.engine.GlideException
import com.bumptech.glide.request.RequestListener
import com.bumptech.glide.request.target.Target
import gallery.memories.MainActivity
import gallery.memories.R
import gallery.memories.dao.AppDatabase
import gallery.memories.mapper.Photo
import gallery.memories.mapper.SystemImage
import gallery.memories.service.ConfigService
import android.location.Geocoder
import androidx.annotation.OptIn
import androidx.exifinterface.media.ExifInterface
import androidx.media3.common.util.UnstableApi
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.io.FileOutputStream
import java.security.SecureRandom
import java.security.cert.X509Certificate
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLContext
import javax.net.ssl.X509TrustManager
import kotlin.coroutines.resume
import kotlin.coroutines.suspendCoroutine
import kotlin.random.Random

@OptIn(UnstableApi::class)
class WidgetWorker(
    private val context: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(context, workerParams) {

    override suspend fun doWork(): Result {
        val appWidgetManager = AppWidgetManager.getInstance(context)
        val appWidgetIds = appWidgetManager.getAppWidgetIds(
            ComponentName(context, MemoriesWidget::class.java)
        )
        if (appWidgetIds.isEmpty()) return Result.success()

        // Try server first (this is where the user's photos live)
        if (tryServerPhoto(appWidgetManager, appWidgetIds)) {
            return Result.success()
        }

        // Server unreachable — try cached server images
        if (tryCachedPhoto(appWidgetManager, appWidgetIds)) {
            Log.d(TAG, "Showing cached server photo (offline mode)")
            return Result.success()
        }

        // Fall back to local DB
        if (tryLocalDbPhoto(appWidgetManager, appWidgetIds)) {
            return Result.success()
        }

        // Fall back to MediaStore
        if (tryMediaStoreFallback(appWidgetManager, appWidgetIds)) {
            return Result.success()
        }

        updateWidgetError(context.getString(R.string.widget_no_photos))
        return Result.success()
    }

    // ========================================================================
    // Image cache (up to MAX_CACHED images in internal storage)
    // ========================================================================

    private fun getCacheDir(): File {
        val dir = File(context.filesDir, CACHE_DIR)
        if (!dir.exists()) dir.mkdirs()
        return dir
    }

    /**
     * Save a bitmap to the widget cache. Maintains a rolling window of
     * MAX_CACHED images, deleting the oldest when the limit is exceeded.
     */
    private fun cacheImage(bitmap: Bitmap, label: String) {
        try {
            val dir = getCacheDir()
            val timestamp = System.currentTimeMillis()
            val file = File(dir, "widget_${timestamp}_${label.hashCode()}.jpg")

            FileOutputStream(file).use { out ->
                bitmap.compress(Bitmap.CompressFormat.JPEG, 85, out)
            }

            // Prune old files if over limit
            val files = dir.listFiles()
                ?.filter { it.name.startsWith("widget_") && it.name.endsWith(".jpg") }
                ?.sortedByDescending { it.lastModified() }
                ?: return

            if (files.size > MAX_CACHED) {
                files.drop(MAX_CACHED).forEach { it.delete() }
            }

            Log.d(TAG, "Cached image: ${file.name} (${files.size.coerceAtMost(MAX_CACHED)} total)")
        } catch (e: Exception) {
            Log.e(TAG, "Failed to cache image", e)
        }
    }

    /**
     * Load a random image from the cache.
     */
    private fun loadCachedImage(): Bitmap? {
        return try {
            val dir = getCacheDir()
            val files = dir.listFiles()
                ?.filter { it.name.startsWith("widget_") && it.name.endsWith(".jpg") }
                ?: return null

            if (files.isEmpty()) return null

            val file = files.random()
            BitmapFactory.decodeFile(file.absolutePath)
        } catch (e: Exception) {
            Log.e(TAG, "Failed to load cached image", e)
            null
        }
    }

    /**
     * Show a cached photo when the server is unreachable.
     */
    private fun tryCachedPhoto(
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray
    ): Boolean {
        val bitmap = loadCachedImage() ?: return false
        updateWidgetWithBitmap(bitmap, appWidgetManager, appWidgetIds)
        return true
    }

    // ========================================================================
    // Server photo fetching
    // ========================================================================

    /**
     * Fetch a photo from the Nextcloud server.
     * Uses the stored credentials to call the Memories API.
     */
    private suspend fun tryServerPhoto(
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray
    ): Boolean {
        try {
            val store = SecureStorage(context)
            val cred = store.getCredentials() ?: run {
                Log.d(TAG, "No server credentials stored")
                return false
            }

            val client = buildOkHttpClient(cred.trustAll)
            val authHeader = "Basic ${Base64.encodeToString(
                "${cred.username}:${cred.token}".toByteArray(), Base64.NO_WRAP
            )}"

            // Fetch list of days from server
            val daysBody = client.newCall(
                buildApiRequest(cred.url, "api/days", authHeader)
            ).execute().use { response ->
                if (response.code != 200) {
                    Log.w(TAG, "Server /api/days returned ${response.code}")
                    return false
                }
                response.body.string()
            }

            val daysArray = JSONArray(daysBody)

            if (daysArray.length() == 0) {
                Log.w(TAG, "Server has no days")
                return false
            }

            // Try "On This Day" first — find days matching today's month-day from previous years
            val today = LocalDate.now()
            val currentYear = today.year
            val todayMonthDay = today.format(DateTimeFormatter.ofPattern("MM-dd"))

            var chosenDay: JSONObject? = null
            var isOnThisDay = false
            val onThisDayCandidates = mutableListOf<JSONObject>()

            for (i in 0 until daysArray.length()) {
                val dayObj = daysArray.getJSONObject(i)
                val dayId = dayObj.getLong("dayid")
                val dayDate = LocalDate.ofEpochDay(dayId)
                val dayMonthDay = dayDate.format(DateTimeFormatter.ofPattern("MM-dd"))
                if (dayMonthDay == todayMonthDay && dayDate.year != currentYear) {
                    onThisDayCandidates.add(dayObj)
                }
            }

            if (onThisDayCandidates.isNotEmpty()) {
                // Weighted selection: 70% On This Day, 30% random
                if (Random.nextDouble() < OTD_WEIGHT) {
                    chosenDay = onThisDayCandidates.random()
                    isOnThisDay = true
                    Log.d(TAG, "Showing 'On This Day' photo (${onThisDayCandidates.size} candidates)")
                } else {
                    val idx = (0 until daysArray.length()).random()
                    chosenDay = daysArray.getJSONObject(idx)
                    Log.d(TAG, "Showing random photo (OTD available but rolled random)")
                }
            } else {
                val idx = (0 until daysArray.length()).random()
                chosenDay = daysArray.getJSONObject(idx)
                Log.d(TAG, "No OTD candidates, showing random photo")
            }

            val dayId = chosenDay.getLong("dayid")

            // Fetch photos for the chosen day
            val dayBody = client.newCall(
                buildApiRequest(cred.url, "api/days/$dayId", authHeader)
            ).execute().use { response ->
                if (response.code != 200) {
                    Log.w(TAG, "Server /api/days/$dayId returned ${response.code}")
                    return false
                }
                response.body.string()
            }

            val photosArray = JSONArray(dayBody)

            if (photosArray.length() == 0) {
                Log.w(TAG, "Day $dayId has no photos")
                return false
            }

            // Pick a random photo
            val photoIdx = (0 until photosArray.length()).random()
            val photoObj = photosArray.getJSONObject(photoIdx)
            val fileId = photoObj.getLong("fileid")

            // Compute "X years ago" text
            val yearsAgoText = if (isOnThisDay) {
                val dayDate = LocalDate.ofEpochDay(dayId)
                val diff = currentYear - dayDate.year
                when {
                    diff == 1 -> context.getString(R.string.widget_one_year_ago)
                    diff > 1 -> context.getString(R.string.widget_years_ago, diff)
                    else -> null
                }
            } else null

            // Try to fetch location/address from server
            var locationText: String? = null
            try {
                client.newCall(
                    buildApiRequest(cred.url, "api/image/info/$fileId", authHeader)
                ).execute().use { infoResponse ->
                    if (infoResponse.code == 200) {
                        val infoJson = JSONObject(infoResponse.body.string())
                        if (infoJson.has("address") && !infoJson.isNull("address")) {
                            locationText = infoJson.getString("address")
                        }
                    }
                }
            } catch (e: Exception) {
                Log.w(TAG, "Failed to fetch photo info for location", e)
            }

            // Download the preview image
            val bytes = client.newCall(
                buildApiRequest(cred.url, "api/image/preview/$fileId?x=1024&y=1024", authHeader)
            ).execute().use { previewResponse ->
                if (previewResponse.code != 200) {
                    Log.w(TAG, "Server preview for $fileId returned ${previewResponse.code}")
                    return false
                }
                previewResponse.body.bytes()
            }

            val bitmap = BitmapFactory.decodeByteArray(bytes, 0, bytes.size)
            if (bitmap == null) {
                Log.e(TAG, "Failed to decode server preview bitmap")
                return false
            }

            // Cache the image for offline use
            cacheImage(bitmap, "server_$fileId")

            Log.d(TAG, "Server photo loaded: fileId=$fileId, ${bitmap.width}x${bitmap.height}")
            updateWidgetWithBitmap(
                bitmap, appWidgetManager, appWidgetIds,
                isOnThisDay = isOnThisDay, yearsAgoText = yearsAgoText,
                locationText = locationText
            )
            return true

        } catch (e: Exception) {
            Log.e(TAG, "Error fetching server photo", e)
            return false
        }
    }

    private fun buildOkHttpClient(trustAll: Boolean): OkHttpClient {
        val builder = OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)

        if (trustAll) {
            val tm = object : X509TrustManager {
                override fun checkClientTrusted(chain: Array<X509Certificate>, authType: String) {}
                override fun checkServerTrusted(chain: Array<X509Certificate>, authType: String) {}
                override fun getAcceptedIssuers(): Array<X509Certificate> = arrayOf()
            }
            val sc = SSLContext.getInstance("TLS")
            sc.init(null, arrayOf(tm), SecureRandom())
            builder.sslSocketFactory(sc.socketFactory, tm)
                .hostnameVerifier { _, _ -> true }
        }

        return builder.build()
    }

    /**
     * Build an authenticated API request for the Memories server.
     */
    private fun buildApiRequest(baseUrl: String, path: String, authHeader: String): Request {
        return Request.Builder()
            .url("$baseUrl$path")
            .header("Authorization", authHeader)
            .header("User-Agent", "MemoriesNative/1.0")
            .header("OCS-APIRequest", "true")
            .header("X-Requested-With", "gallery.memories")
            .get()
            .build()
    }

    // ========================================================================
    // Local DB photo fetching
    // ========================================================================

    private suspend fun tryLocalDbPhoto(
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray
    ): Boolean {
        // Check permission
        val hasPerm = androidx.core.content.ContextCompat.checkSelfPermission(
            context, "android.permission.READ_MEDIA_IMAGES"
        ) == android.content.pm.PackageManager.PERMISSION_GRANTED ||
            androidx.core.content.ContextCompat.checkSelfPermission(
                context, "android.permission.READ_EXTERNAL_STORAGE"
            ) == android.content.pm.PackageManager.PERMISSION_GRANTED

        if (!hasPerm) return false

        val db = AppDatabase.get(context)
        val photoDao = db.photoDao()
        val configService = ConfigService(context)
        val bucketIds = configService.enabledBucketIds

        val today = Instant.now().atZone(ZoneId.systemDefault())
        val dateStr = DateTimeFormatter.ofPattern("MM-dd").format(today)

        val photos = if (bucketIds.isEmpty()) {
            photoDao.getOnThisDayPhotosAny(dateStr)
        } else {
            photoDao.getOnThisDayPhotos(dateStr, bucketIds)
        }

        var isOnThisDay = false
        var photo: Photo? = null

        if (photos.isNotEmpty() && Random.nextDouble() < OTD_WEIGHT) {
            // Weighted selection: prioritize On This Day
            photo = photos.random()
            isOnThisDay = true
        } else {
            // Pick a random photo from all photos
            photo = if (bucketIds.isEmpty()) {
                photoDao.getRandomPhotoAny()
            } else {
                photoDao.getRandomPhoto(bucketIds)
            }
            // If random returned null but OTD had photos, use OTD as fallback
            if (photo == null && photos.isNotEmpty()) {
                photo = photos.random()
                isOnThisDay = true
            }
        }

        if (photo == null) return false

        val yearsAgoText = if (isOnThisDay) {
            val photoYear = Instant.ofEpochSecond(photo.dateTaken)
                .atZone(ZoneId.systemDefault()).year
            val currentYear = today.year
            val diff = currentYear - photoYear
            when {
                diff == 1 -> context.getString(R.string.widget_one_year_ago)
                diff > 1 -> context.getString(R.string.widget_years_ago, diff)
                else -> null
            }
        } else null

        val systemImages = SystemImage.getByIds(context, listOf(photo.localId))
        if (systemImages.isEmpty()) return false

        // Try to get location from EXIF GPS data
        val locationText = getLocationFromSystemImage(systemImages[0])

        loadLocalBitmapAndUpdateWidget(
            systemImages[0], appWidgetManager, appWidgetIds,
            isOnThisDay = isOnThisDay, yearsAgoText = yearsAgoText,
            locationText = locationText
        )
        return true
    }

    // ========================================================================
    // MediaStore fallback
    // ========================================================================

    private suspend fun tryMediaStoreFallback(
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray
    ): Boolean {
        val hasPerm = androidx.core.content.ContextCompat.checkSelfPermission(
            context, "android.permission.READ_MEDIA_IMAGES"
        ) == android.content.pm.PackageManager.PERMISSION_GRANTED ||
            androidx.core.content.ContextCompat.checkSelfPermission(
                context, "android.permission.READ_EXTERNAL_STORAGE"
            ) == android.content.pm.PackageManager.PERMISSION_GRANTED

        if (!hasPerm) return false

        return try {
            var photos = SystemImage.cursor(
                context, SystemImage.IMAGE_URI, null, null,
                "${android.provider.MediaStore.Images.Media.DATE_TAKEN} DESC"
            ).take(1).toList()

            if (photos.isEmpty()) {
                photos = SystemImage.cursor(
                    context, SystemImage.VIDEO_URI, null, null,
                    "${android.provider.MediaStore.Video.Media.DATE_TAKEN} DESC"
                ).take(1).toList()
            }

            val sysImg = photos.firstOrNull()
            if (sysImg != null) {
                val locationText = getLocationFromSystemImage(sysImg)
                loadLocalBitmapAndUpdateWidget(
                    sysImg, appWidgetManager, appWidgetIds,
                    locationText = locationText
                )
                true
            } else false
        } catch (e: Exception) {
            Log.e(TAG, "MediaStore fallback error", e)
            false
        }
    }

    // ========================================================================
    // Bitmap loading & widget update
    // ========================================================================

    private suspend fun loadLocalBitmapAndUpdateWidget(
        systemImage: SystemImage,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
        isOnThisDay: Boolean = false,
        yearsAgoText: String? = null,
        locationText: String? = null
    ) {
        val loadSource: Any = systemImage.uri
        val bitmap = suspendCoroutine<Bitmap?> { continuation ->
            Glide.with(context)
                .asBitmap()
                .load(loadSource)
                .override(800, 800)
                .centerCrop()
                .listener(object : RequestListener<Bitmap> {
                    override fun onLoadFailed(
                        e: GlideException?, model: Any?,
                        target: Target<Bitmap>, isFirstResource: Boolean
                    ): Boolean {
                        continuation.resume(null)
                        return false
                    }

                    override fun onResourceReady(
                        resource: Bitmap, model: Any,
                        target: Target<Bitmap>?, dataSource: DataSource,
                        isFirstResource: Boolean
                    ): Boolean {
                        continuation.resume(resource)
                        return false
                    }
                })
                .submit()
        }

        if (bitmap == null) {
            Log.e(TAG, "Failed to load local bitmap")
            return
        }

        updateWidgetWithBitmap(
            bitmap, appWidgetManager, appWidgetIds,
            isOnThisDay = isOnThisDay, yearsAgoText = yearsAgoText,
            locationText = locationText
        )
    }

    private fun updateWidgetWithBitmap(
        bitmap: Bitmap,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
        isOnThisDay: Boolean = false,
        yearsAgoText: String? = null,
        locationText: String? = null
    ) {
        for (appWidgetId in appWidgetIds) {
            val views = RemoteViews(context.packageName, R.layout.widget_memories)

            views.setImageViewBitmap(R.id.widget_image, bitmap)
            views.setViewVisibility(R.id.widget_image, View.VISIBLE)
            views.setViewVisibility(R.id.widget_empty_text, View.GONE)

            // Show location text if available
            if (!locationText.isNullOrBlank()) {
                views.setTextViewText(R.id.widget_location, "\uD83D\uDCCD $locationText")
                views.setViewVisibility(R.id.widget_location, View.VISIBLE)
                views.setViewVisibility(R.id.widget_scrim_top, View.VISIBLE)
            } else {
                views.setViewVisibility(R.id.widget_location, View.GONE)
                views.setViewVisibility(R.id.widget_scrim_top, View.GONE)
            }

            if (isOnThisDay) {
                views.setViewVisibility(R.id.widget_label, View.VISIBLE)
                views.setTextViewText(R.id.widget_label,
                    context.getString(R.string.widget_on_this_day))
                if (yearsAgoText != null) {
                    views.setViewVisibility(R.id.widget_date, View.VISIBLE)
                    views.setTextViewText(R.id.widget_date, yearsAgoText)
                } else {
                    views.setViewVisibility(R.id.widget_date, View.GONE)
                }
            } else {
                views.setViewVisibility(R.id.widget_label, View.GONE)
                views.setViewVisibility(R.id.widget_date, View.GONE)
            }

            // Click to open app
            val openIntent = Intent(context, MainActivity::class.java)
            val openPending = PendingIntent.getActivity(
                context, 0, openIntent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            views.setOnClickPendingIntent(R.id.widget_root, openPending)

            // Refresh button
            val refreshIntent = Intent(context, MemoriesWidget::class.java).apply {
                action = MemoriesWidget.ACTION_REFRESH
            }
            val refreshPending = PendingIntent.getBroadcast(
                context, 0, refreshIntent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            views.setOnClickPendingIntent(R.id.widget_refresh_btn, refreshPending)

            appWidgetManager.updateAppWidget(appWidgetId, views)
        }
    }

    private fun updateWidgetError(message: String) {
        val appWidgetManager = AppWidgetManager.getInstance(context)
        val appWidgetIds = appWidgetManager.getAppWidgetIds(
            ComponentName(context, MemoriesWidget::class.java)
        )

        for (appWidgetId in appWidgetIds) {
            val views = RemoteViews(context.packageName, R.layout.widget_memories)
            views.setTextViewText(R.id.widget_empty_text, message)
            views.setViewVisibility(R.id.widget_empty_text, View.VISIBLE)
            views.setViewVisibility(R.id.widget_image, View.GONE)
            views.setViewVisibility(R.id.widget_label, View.GONE)
            views.setViewVisibility(R.id.widget_date, View.GONE)
            views.setViewVisibility(R.id.widget_location, View.GONE)
            views.setViewVisibility(R.id.widget_scrim_top, View.GONE)

            val intent = Intent(context, MainActivity::class.java)
            val pendingIntent = PendingIntent.getActivity(
                context, 0, intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            views.setOnClickPendingIntent(R.id.widget_root, pendingIntent)

            appWidgetManager.updateAppWidget(appWidgetId, views)
        }
    }

    // ========================================================================
    // Location helpers
    // ========================================================================

    /**
     * Try to extract GPS coordinates from a local image's EXIF data
     * and reverse-geocode them to a human-readable location string.
     */
    private fun getLocationFromSystemImage(systemImage: SystemImage): String? {
        if (systemImage.dataPath.isEmpty() || systemImage.isVideo) return null
        try {
            val exif = ExifInterface(systemImage.dataPath)
            val latLong = exif.latLong ?: return null
            return reverseGeocode(latLong[0], latLong[1])
        } catch (e: Exception) {
            Log.w(TAG, "Failed to read EXIF GPS: ${e.message}")
        }
        return null
    }

    /**
     * Reverse-geocode latitude/longitude to a compact location string
     * like "City, Country" or "Region, Country".
     */
    private fun reverseGeocode(lat: Double, lon: Double): String? {
        try {
            @Suppress("DEPRECATION")
            val addresses = Geocoder(context, Locale.getDefault()).getFromLocation(lat, lon, 1)
            if (!addresses.isNullOrEmpty()) {
                val addr = addresses[0]
                val parts = mutableListOf<String>()
                addr.locality?.let { parts.add(it) }
                if (parts.isEmpty()) addr.adminArea?.let { parts.add(it) }
                addr.countryName?.let { parts.add(it) }
                return if (parts.isNotEmpty()) parts.joinToString(", ") else null
            }
        } catch (e: Exception) {
            Log.w(TAG, "Geocoding failed: ${e.message}")
        }
        return null
    }

    companion object {
        private const val TAG = "MemoriesWidgetWorker"
        private const val CACHE_DIR = "widget_cache"
        private const val MAX_CACHED = 10
        /** Weight for On This Day photos (0.0–1.0). Higher = more OTD photos. */
        private const val OTD_WEIGHT = 0.7
    }
}
