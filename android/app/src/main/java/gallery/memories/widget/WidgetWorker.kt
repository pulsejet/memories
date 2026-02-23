package gallery.memories.widget

import SecureStorage
import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.location.Geocoder
import android.provider.MediaStore
import android.util.Base64
import android.util.Log
import android.view.View
import android.widget.RemoteViews
import androidx.annotation.OptIn
import androidx.core.content.ContextCompat
import androidx.exifinterface.media.ExifInterface
import androidx.media3.common.util.UnstableApi
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
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

/**
 * Background worker that fetches a photo and updates all Memories widget instances.
 *
 * Photo sources are tried in priority order:
 * 1. Nextcloud Memories server (with On-This-Day weighting)
 * 2. Cached server images (offline fallback)
 * 3. Local Room DB photos
 * 4. MediaStore fallback (most recent photo)
 *
 * After each run the worker self-schedules the next update via [scheduleNextUpdate].
 */
@OptIn(UnstableApi::class)
class WidgetWorker(
    private val context: Context,
    workerParams: WorkerParameters,
) : CoroutineWorker(context, workerParams) {

    // ════════════════════════════════════════════════════════════════════════
    // Main entry point
    // ════════════════════════════════════════════════════════════════════════

    override suspend fun doWork(): Result {
        val appWidgetManager = AppWidgetManager.getInstance(context)
        val appWidgetIds = appWidgetManager.getAppWidgetIds(
            ComponentName(context, MemoriesWidget::class.java)
        )
        if (appWidgetIds.isEmpty()) return Result.success()

        val photoSources: List<suspend () -> Boolean> = listOf(
            { tryServerPhoto(appWidgetManager, appWidgetIds) },
            { tryCachedPhoto(appWidgetManager, appWidgetIds) },
            { tryLocalDbPhoto(appWidgetManager, appWidgetIds) },
            { tryMediaStoreFallback(appWidgetManager, appWidgetIds) },
        )

        val loaded = photoSources.firstNotNullOfOrNull { source ->
            try {
                if (source()) true else null
            } catch (e: Exception) {
                Log.e(TAG, "Photo source failed", e)
                null
            }
        } != null

        if (!loaded) {
            showError(context.getString(R.string.widget_no_photos))
        }

        scheduleNextUpdate()
        return Result.success()
    }

    /** Enqueue the next auto-update after [MemoriesWidget.UPDATE_INTERVAL_MINUTES]. */
    private fun scheduleNextUpdate() {
        val request = OneTimeWorkRequestBuilder<WidgetWorker>()
            .setInitialDelay(MemoriesWidget.UPDATE_INTERVAL_MINUTES, TimeUnit.MINUTES)
            .build()

        WorkManager.getInstance(context).enqueueUniqueWork(
            WORK_NAME_AUTO,
            ExistingWorkPolicy.REPLACE,
            request,
        )
    }

    // ════════════════════════════════════════════════════════════════════════
    // Data model
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Metadata displayed alongside the widget photo.
     * Persisted to a JSON sidecar file so cached photos retain their labels.
     */
    private data class WidgetMetadata(
        val labelText: String? = null,
        val dateText: String? = null,
        val locationText: String? = null,
        val photoUri: String? = null,
    ) {
        fun toJson(): JSONObject = JSONObject().apply {
            put(KEY_LABEL, labelText ?: JSONObject.NULL)
            put(KEY_DATE, dateText ?: JSONObject.NULL)
            put(KEY_LOCATION, locationText ?: JSONObject.NULL)
            put(KEY_PHOTO_URI, photoUri ?: JSONObject.NULL)
        }

        companion object {
            private const val KEY_LABEL = "labelText"
            private const val KEY_DATE = "dateText"
            private const val KEY_LOCATION = "locationText"
            private const val KEY_PHOTO_URI = "photoUri"

            fun fromJson(json: JSONObject) = WidgetMetadata(
                labelText = json.optString(KEY_LABEL, "").ifBlank { null },
                dateText = json.optString(KEY_DATE, "").ifBlank { null },
                locationText = json.optString(KEY_LOCATION, "").ifBlank { null },
                photoUri = json.optString(KEY_PHOTO_URI, "").ifBlank { null },
            )
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Image cache
    // ════════════════════════════════════════════════════════════════════════

    private fun getCacheDir(): File {
        val dir = File(context.filesDir, CACHE_DIR)
        if (!dir.exists()) dir.mkdirs()
        return dir
    }

    /**
     * Save [bitmap] and its [metadata] to the cache directory.
     * Maintains a rolling window of [MAX_CACHED] images.
     */
    private fun cacheImage(bitmap: Bitmap, tag: String, metadata: WidgetMetadata) {
        try {
            val dir = getCacheDir()
            val baseName = "widget_${System.currentTimeMillis()}_${tag.hashCode()}"

            File(dir, "$baseName.jpg").outputStream().use { out ->
                bitmap.compress(Bitmap.CompressFormat.JPEG, IMAGE_QUALITY, out)
            }

            File(dir, "$baseName.json").writeText(metadata.toJson().toString())

            pruneCache(dir)
        } catch (e: Exception) {
            Log.e(TAG, "Failed to cache image", e)
        }
    }

    /** Delete oldest cached images when the cache exceeds [MAX_CACHED]. */
    private fun pruneCache(dir: File) {
        val images = dir.listFiles()
            ?.filter { it.name.startsWith("widget_") && it.extension == "jpg" }
            ?.sortedByDescending { it.lastModified() }
            ?: return

        if (images.size > MAX_CACHED) {
            images.drop(MAX_CACHED).forEach { expired ->
                expired.delete()
                sidecarFor(expired).delete()
            }
        }

        Log.d(TAG, "Cache: ${images.size.coerceAtMost(MAX_CACHED)} images")
    }

    /** Load a random cached image with its metadata. Returns null if cache is empty. */
    private fun loadCachedImage(): Pair<Bitmap, WidgetMetadata>? {
        return try {
            val dir = getCacheDir()
            val file = dir.listFiles()
                ?.filter { it.name.startsWith("widget_") && it.extension == "jpg" }
                ?.randomOrNull()
                ?: return null

            val bitmap = BitmapFactory.decodeFile(file.absolutePath) ?: return null
            val metadata = readSidecar(sidecarFor(file))

            Pair(bitmap, metadata)
        } catch (e: Exception) {
            Log.e(TAG, "Failed to load cached image", e)
            null
        }
    }

    /** Read [WidgetMetadata] from a JSON sidecar, returning empty metadata on failure. */
    private fun readSidecar(file: File): WidgetMetadata {
        if (!file.exists()) return WidgetMetadata()
        return try {
            WidgetMetadata.fromJson(JSONObject(file.readText()))
        } catch (e: Exception) {
            Log.w(TAG, "Failed to read sidecar metadata", e)
            WidgetMetadata()
        }
    }

    /** Get the `.json` sidecar path for a `.jpg` cache file. */
    private fun sidecarFor(imageFile: File): File =
        File(imageFile.absolutePath.replace(".jpg", ".json"))

    // ════════════════════════════════════════════════════════════════════════
    // Source 1: Cached server images
    // ════════════════════════════════════════════════════════════════════════

    private fun tryCachedPhoto(
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
    ): Boolean {
        val (bitmap, metadata) = loadCachedImage() ?: return false
        Log.d(TAG, "Showing cached server photo (offline mode)")
        applyWidgetUpdate(bitmap, metadata, appWidgetManager, appWidgetIds)
        return true
    }

    // ════════════════════════════════════════════════════════════════════════
    // Source 2: Nextcloud Memories server
    // ════════════════════════════════════════════════════════════════════════

    private suspend fun tryServerPhoto(
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
    ): Boolean {
        val store = SecureStorage(context)
        val cred = store.getCredentials() ?: run {
            Log.d(TAG, "No server credentials stored")
            return false
        }

        val client = buildOkHttpClient(cred.trustAll)
        val authHeader = buildAuthHeader(cred.username, cred.token)

        // 1. Fetch available days
        val daysArray = fetchDays(client, cred.url, authHeader) ?: return false
        if (daysArray.length() == 0) {
            Log.w(TAG, "Server has no days")
            return false
        }

        // 2. Select day (On-This-Day weighted)
        val (dayId, isOtd) = selectDay(daysArray)

        // 3. Pick a random photo from that day
        val fileId = fetchRandomPhotoId(client, cred.url, authHeader, dayId)
            ?: return false

        // 4. Build metadata (label, date, location, deep link)
        val metadata = buildServerMetadata(
            client, cred.url, authHeader,
            dayId = dayId, fileId = fileId, isOnThisDay = isOtd,
        )

        // 5. Download preview bitmap
        val bitmap = downloadPreview(client, cred.url, authHeader, fileId)
            ?: return false

        // 6. Cache and display
        cacheImage(bitmap, "server_$fileId", metadata)
        Log.d(TAG, "Server photo: fileId=$fileId, ${bitmap.width}x${bitmap.height}")
        applyWidgetUpdate(bitmap, metadata, appWidgetManager, appWidgetIds)
        return true
    }

    // -- Server: API calls ------------------------------------------------

    /** Fetch the list of days from `/api/days`. Returns null on failure. */
    private fun fetchDays(
        client: OkHttpClient,
        baseUrl: String,
        authHeader: String,
    ): JSONArray? {
        return client.newCall(
            buildApiRequest(baseUrl, "api/days", authHeader)
        ).execute().use { response ->
            if (response.code != 200) {
                Log.w(TAG, "Server /api/days returned ${response.code}")
                return null
            }
            JSONArray(response.body.string())
        }
    }

    /** Fetch photos for [dayId] and return a random file ID, or null on failure. */
    private fun fetchRandomPhotoId(
        client: OkHttpClient,
        baseUrl: String,
        authHeader: String,
        dayId: Long,
    ): Long? {
        val photosArray = client.newCall(
            buildApiRequest(baseUrl, "api/days/$dayId", authHeader)
        ).execute().use { response ->
            if (response.code != 200) {
                Log.w(TAG, "Server /api/days/$dayId returned ${response.code}")
                return null
            }
            JSONArray(response.body.string())
        }

        if (photosArray.length() == 0) {
            Log.w(TAG, "Day $dayId has no photos")
            return null
        }

        val idx = (0 until photosArray.length()).random()
        return photosArray.getJSONObject(idx).getLong("fileid")
    }

    /** Fetch the address from `/api/image/info/{fileId}`, or null. */
    private fun fetchServerLocation(
        client: OkHttpClient,
        baseUrl: String,
        authHeader: String,
        fileId: Long,
    ): String? {
        return try {
            client.newCall(
                buildApiRequest(baseUrl, "api/image/info/$fileId", authHeader)
            ).execute().use { response ->
                if (response.code != 200) return null
                val json = JSONObject(response.body.string())
                json.optString("address", "").ifBlank { null }
            }
        } catch (e: Exception) {
            Log.w(TAG, "Failed to fetch photo location info", e)
            null
        }
    }

    /** Download a preview bitmap for [fileId], or null on failure. */
    private fun downloadPreview(
        client: OkHttpClient,
        baseUrl: String,
        authHeader: String,
        fileId: Long,
    ): Bitmap? {
        val bytes = client.newCall(
            buildApiRequest(baseUrl, "api/image/preview/$fileId?x=1024&y=1024", authHeader)
        ).execute().use { response ->
            if (response.code != 200) {
                Log.w(TAG, "Server preview for $fileId returned ${response.code}")
                return null
            }
            response.body.bytes()
        }

        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size)
            ?: run { Log.e(TAG, "Failed to decode server preview"); null }
    }

    // -- Server: day selection --------------------------------------------

    /**
     * Select a day from [daysArray] with On-This-Day weighting.
     * Returns (dayId, isOnThisDay).
     */
    private fun selectDay(daysArray: JSONArray): Pair<Long, Boolean> {
        val today = LocalDate.now()
        val todayMonthDay = today.format(MONTH_DAY_FORMAT)

        val otdCandidates = (0 until daysArray.length())
            .map { daysArray.getJSONObject(it) }
            .filter { dayObj ->
                val dayDate = LocalDate.ofEpochDay(dayObj.getLong("dayid"))
                dayDate.format(MONTH_DAY_FORMAT) == todayMonthDay && dayDate.year != today.year
            }

        // Weighted roll: prefer OTD when available
        if (otdCandidates.isNotEmpty() && Random.nextDouble() < OTD_WEIGHT) {
            val chosen = otdCandidates.random()
            Log.d(TAG, "Selected OTD (${otdCandidates.size} candidates)")
            return chosen.getLong("dayid") to true
        }

        // Random day fallback
        val idx = (0 until daysArray.length()).random()
        val dayId = daysArray.getJSONObject(idx).getLong("dayid")
        Log.d(TAG, if (otdCandidates.isNotEmpty()) "Rolled random (OTD available)" else "No OTD, random day")
        return dayId to false
    }

    // -- Server: metadata -------------------------------------------------

    /** Build [WidgetMetadata] for a server photo. */
    private fun buildServerMetadata(
        client: OkHttpClient,
        baseUrl: String,
        authHeader: String,
        dayId: Long,
        fileId: Long,
        isOnThisDay: Boolean,
    ): WidgetMetadata {
        val dayDate = LocalDate.ofEpochDay(dayId)
        val (labelText, dateText) = buildLabelAndDate(isOnThisDay, dayDate)
        val locationText = fetchServerLocation(client, baseUrl, authHeader, fileId)
        val photoUri = "#v/$dayId/$fileId"

        return WidgetMetadata(labelText, dateText, locationText, photoUri)
    }

    // -- HTTP helpers -----------------------------------------------------

    private fun buildAuthHeader(username: String, token: String): String =
        "Basic ${Base64.encodeToString("$username:$token".toByteArray(), Base64.NO_WRAP)}"

    private fun buildOkHttpClient(trustAll: Boolean): OkHttpClient {
        val builder = OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)

        if (trustAll) {
            val trustManager = object : X509TrustManager {
                override fun checkClientTrusted(chain: Array<X509Certificate>, type: String) {}
                override fun checkServerTrusted(chain: Array<X509Certificate>, type: String) {}
                override fun getAcceptedIssuers(): Array<X509Certificate> = arrayOf()
            }
            val sslContext = SSLContext.getInstance("TLS").apply {
                init(null, arrayOf(trustManager), SecureRandom())
            }
            builder.sslSocketFactory(sslContext.socketFactory, trustManager)
                .hostnameVerifier { _, _ -> true }
        }

        return builder.build()
    }

    /** Build an authenticated API request for the Memories server. */
    private fun buildApiRequest(baseUrl: String, path: String, authHeader: String): Request =
        Request.Builder()
            .url("$baseUrl$path")
            .header("Authorization", authHeader)
            .header("User-Agent", USER_AGENT)
            .header("OCS-APIRequest", "true")
            .header("X-Requested-With", PACKAGE_ID)
            .get()
            .build()

    // ════════════════════════════════════════════════════════════════════════
    // Source 3: Local Room DB
    // ════════════════════════════════════════════════════════════════════════

    private suspend fun tryLocalDbPhoto(
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
    ): Boolean {
        if (!hasMediaPermission()) return false

        val photoDao = AppDatabase.get(context).photoDao()
        val bucketIds = ConfigService(context).enabledBucketIds

        val today = Instant.now().atZone(ZoneId.systemDefault())
        val dateStr = MONTH_DAY_FORMAT.format(today)

        val otdPhotos = if (bucketIds.isEmpty()) {
            photoDao.getOnThisDayPhotosAny(dateStr)
        } else {
            photoDao.getOnThisDayPhotos(dateStr, bucketIds)
        }

        val (photo, isOtd) = selectLocalPhoto(otdPhotos, photoDao, bucketIds)
            ?: return false

        val photoDate = Instant.ofEpochSecond(photo.dateTaken)
            .atZone(ZoneId.systemDefault())
        val (labelText, dateText) = buildLabelAndDate(isOtd, photoDate.toLocalDate(), today.year)

        val systemImage = SystemImage.getByIds(context, listOf(photo.localId))
            .firstOrNull() ?: return false

        val metadata = WidgetMetadata(
            labelText = labelText,
            dateText = dateText,
            locationText = getLocationFromExif(systemImage),
            photoUri = systemImage.uri.toString(),
        )
        loadBitmapAndApply(systemImage, metadata, appWidgetManager, appWidgetIds)
        return true
    }

    /**
     * Select a photo with On-This-Day weighting.
     * Returns (photo, isOnThisDay), or null if no photos are available.
     */
    private fun selectLocalPhoto(
        otdPhotos: List<Photo>,
        photoDao: gallery.memories.dao.PhotoDao,
        bucketIds: List<String>,
    ): Pair<Photo, Boolean>? {
        if (otdPhotos.isNotEmpty() && Random.nextDouble() < OTD_WEIGHT) {
            return otdPhotos.random() to true
        }

        val randomPhoto = if (bucketIds.isEmpty()) {
            photoDao.getRandomPhotoAny()
        } else {
            photoDao.getRandomPhoto(bucketIds)
        }

        if (randomPhoto != null) return randomPhoto to false
        if (otdPhotos.isNotEmpty()) return otdPhotos.random() to true
        return null
    }

    // ════════════════════════════════════════════════════════════════════════
    // Source 4: MediaStore fallback
    // ════════════════════════════════════════════════════════════════════════

    private suspend fun tryMediaStoreFallback(
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
    ): Boolean {
        if (!hasMediaPermission()) return false

        return try {
            val systemImage = queryMostRecentImage() ?: return false
            val dateText = if (systemImage.dateTaken > 0) {
                formatPhotoDate(systemImage.dateTaken / 1000)
            } else null

            val metadata = WidgetMetadata(
                labelText = getRandomMemoryLabel(),
                dateText = dateText,
                locationText = getLocationFromExif(systemImage),
                photoUri = systemImage.uri.toString(),
            )
            loadBitmapAndApply(systemImage, metadata, appWidgetManager, appWidgetIds)
            true
        } catch (e: Exception) {
            Log.e(TAG, "MediaStore fallback error", e)
            false
        }
    }

    /** Query MediaStore for the most recent image (or video if none). */
    private fun queryMostRecentImage(): SystemImage? {
        val sortOrder = "${MediaStore.Images.Media.DATE_TAKEN} DESC"

        return SystemImage.cursor(context, SystemImage.IMAGE_URI, null, null, sortOrder)
            .take(1).toList().firstOrNull()
            ?: SystemImage.cursor(context, SystemImage.VIDEO_URI, null, null, sortOrder)
                .take(1).toList().firstOrNull()
    }

    // ════════════════════════════════════════════════════════════════════════
    // Bitmap loading
    // ════════════════════════════════════════════════════════════════════════

    /** Load a local [systemImage] via Glide and apply the widget update. */
    private suspend fun loadBitmapAndApply(
        systemImage: SystemImage,
        metadata: WidgetMetadata,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
    ) {
        val bitmap = loadBitmap(systemImage) ?: run {
            Log.e(TAG, "Failed to load local bitmap")
            return
        }
        applyWidgetUpdate(bitmap, metadata, appWidgetManager, appWidgetIds)
    }

    /** Load a sized bitmap from a [SystemImage] using Glide. */
    private suspend fun loadBitmap(systemImage: SystemImage): Bitmap? =
        suspendCoroutine { continuation ->
            Glide.with(context)
                .asBitmap()
                .load(systemImage.uri)
                .override(BITMAP_SIZE, BITMAP_SIZE)
                .centerCrop()
                .listener(object : RequestListener<Bitmap> {
                    override fun onLoadFailed(
                        e: GlideException?, model: Any?,
                        target: Target<Bitmap>, isFirstResource: Boolean,
                    ): Boolean {
                        continuation.resume(null)
                        return false
                    }

                    override fun onResourceReady(
                        resource: Bitmap, model: Any,
                        target: Target<Bitmap>?, dataSource: DataSource,
                        isFirstResource: Boolean,
                    ): Boolean {
                        continuation.resume(resource)
                        return false
                    }
                })
                .submit()
        }

    // ════════════════════════════════════════════════════════════════════════
    // Widget RemoteViews update
    // ════════════════════════════════════════════════════════════════════════

    /** Apply [bitmap] and [metadata] to all widget instances. */
    private fun applyWidgetUpdate(
        bitmap: Bitmap,
        metadata: WidgetMetadata,
        appWidgetManager: AppWidgetManager,
        appWidgetIds: IntArray,
    ) {
        for (appWidgetId in appWidgetIds) {
            val views = RemoteViews(context.packageName, R.layout.widget_memories)

            views.setImageViewBitmap(R.id.widget_image, bitmap)
            views.setViewVisibility(R.id.widget_image, View.VISIBLE)
            views.setViewVisibility(R.id.widget_empty_text, View.GONE)

            applyLocation(views, metadata.locationText)
            applyLabelAndDate(views, metadata.labelText, metadata.dateText)

            views.setOnClickPendingIntent(
                R.id.widget_root, buildPhotoPendingIntent(appWidgetId, metadata.photoUri),
            )
            views.setOnClickPendingIntent(
                R.id.widget_refresh_btn, buildRefreshPendingIntent(),
            )

            appWidgetManager.updateAppWidget(appWidgetId, views)
        }
    }

    /** Show an error message on all widget instances. */
    private fun showError(message: String) {
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

            views.setOnClickPendingIntent(
                R.id.widget_root, buildPhotoPendingIntent(appWidgetId, photoUri = null),
            )

            appWidgetManager.updateAppWidget(appWidgetId, views)
        }
    }

    // -- RemoteViews helpers -----------------------------------------------

    private fun applyLocation(views: RemoteViews, locationText: String?) {
        if (!locationText.isNullOrBlank()) {
            views.setTextViewText(R.id.widget_location, "\uD83D\uDCCD $locationText")
            views.setViewVisibility(R.id.widget_location, View.VISIBLE)
            views.setViewVisibility(R.id.widget_scrim_top, View.VISIBLE)
        } else {
            views.setViewVisibility(R.id.widget_location, View.GONE)
            views.setViewVisibility(R.id.widget_scrim_top, View.GONE)
        }
    }

    private fun applyLabelAndDate(views: RemoteViews, labelText: String?, dateText: String?) {
        if (!labelText.isNullOrBlank()) {
            views.setViewVisibility(R.id.widget_label, View.VISIBLE)
            views.setTextViewText(R.id.widget_label, labelText)
            if (!dateText.isNullOrBlank()) {
                views.setViewVisibility(R.id.widget_date, View.VISIBLE)
                views.setTextViewText(R.id.widget_date, dateText)
            } else {
                views.setViewVisibility(R.id.widget_date, View.GONE)
            }
        } else {
            views.setViewVisibility(R.id.widget_label, View.GONE)
            views.setViewVisibility(R.id.widget_date, View.GONE)
        }
    }

    /** Build a [PendingIntent] that opens the clicked photo (server deep link or local URI). */
    private fun buildPhotoPendingIntent(appWidgetId: Int, photoUri: String?): PendingIntent {
        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            if (!photoUri.isNullOrBlank()) {
                if (photoUri.startsWith("content://")) {
                    putExtra(MemoriesWidget.EXTRA_LOCAL_PHOTO_URI, photoUri)
                } else {
                    putExtra(MemoriesWidget.EXTRA_PHOTO_SUBPATH, photoUri)
                }
            }
        }
        return PendingIntent.getActivity(
            context, appWidgetId, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
    }

    /** Build a [PendingIntent] for the refresh button. */
    private fun buildRefreshPendingIntent(): PendingIntent {
        val intent = Intent(context, MemoriesWidget::class.java).apply {
            action = MemoriesWidget.ACTION_REFRESH
        }
        return PendingIntent.getBroadcast(
            context, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
    }

    // ════════════════════════════════════════════════════════════════════════
    // Label & date helpers
    // ════════════════════════════════════════════════════════════════════════

    /** Pick a random label from the set of non-OTD memory labels. */
    private fun getRandomMemoryLabel(): String {
        val labels = intArrayOf(
            R.string.widget_from_memories,
            R.string.widget_throwback,
            R.string.widget_remember_this,
            R.string.widget_rediscover,
        )
        return context.getString(labels.random())
    }

    /**
     * Build the label and date subtitle for a photo.
     * OTD photos show "On this day" / "X years ago"; others show a random label / date.
     */
    private fun buildLabelAndDate(
        isOnThisDay: Boolean,
        photoDate: LocalDate,
        currentYear: Int = LocalDate.now().year,
    ): Pair<String, String?> {
        if (isOnThisDay) {
            val diff = currentYear - photoDate.year
            val dateText = when {
                diff == 1 -> context.getString(R.string.widget_one_year_ago)
                diff > 1 -> context.getString(R.string.widget_years_ago, diff)
                else -> null
            }
            return context.getString(R.string.widget_on_this_day) to dateText
        }

        return getRandomMemoryLabel() to photoDate.format(DISPLAY_DATE_FORMAT)
    }

    /** Format an epoch-second timestamp as e.g. "March 15, 2019". */
    private fun formatPhotoDate(epochSeconds: Long): String =
        Instant.ofEpochSecond(epochSeconds)
            .atZone(ZoneId.systemDefault())
            .toLocalDate()
            .format(DISPLAY_DATE_FORMAT)

    // ════════════════════════════════════════════════════════════════════════
    // Location helpers
    // ════════════════════════════════════════════════════════════════════════

    /** Extract GPS coordinates from EXIF and reverse-geocode to a location string. */
    private fun getLocationFromExif(systemImage: SystemImage): String? {
        if (systemImage.dataPath.isEmpty() || systemImage.isVideo) return null
        return try {
            val latLong = ExifInterface(systemImage.dataPath).latLong ?: return null
            reverseGeocode(latLong[0], latLong[1])
        } catch (e: Exception) {
            Log.w(TAG, "Failed to read EXIF GPS: ${e.message}")
            null
        }
    }

    /** Reverse-geocode [lat]/[lon] to a compact "City, Country" string. */
    private fun reverseGeocode(lat: Double, lon: Double): String? {
        return try {
            @Suppress("DEPRECATION")
            val addresses = Geocoder(context, Locale.getDefault()).getFromLocation(lat, lon, 1)
            if (addresses.isNullOrEmpty()) return null

            val addr = addresses[0]
            val parts = mutableListOf<String>()
            addr.locality?.let { parts.add(it) }
            if (parts.isEmpty()) addr.adminArea?.let { parts.add(it) }
            addr.countryName?.let { parts.add(it) }
            parts.joinToString(", ").ifBlank { null }
        } catch (e: Exception) {
            Log.w(TAG, "Geocoding failed: ${e.message}")
            null
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Permission helper
    // ════════════════════════════════════════════════════════════════════════

    /** Check whether the app has permission to read media images. */
    private fun hasMediaPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, "android.permission.READ_MEDIA_IMAGES") ==
            PackageManager.PERMISSION_GRANTED ||
        ContextCompat.checkSelfPermission(context, "android.permission.READ_EXTERNAL_STORAGE") ==
            PackageManager.PERMISSION_GRANTED

    // ════════════════════════════════════════════════════════════════════════
    // Constants
    // ════════════════════════════════════════════════════════════════════════

    companion object {
        private const val TAG = "MemoriesWidgetWorker"
        private const val CACHE_DIR = "widget_cache"
        private const val MAX_CACHED = 10
        private const val IMAGE_QUALITY = 85
        private const val BITMAP_SIZE = 800
        private const val USER_AGENT = "MemoriesNative/1.0"
        private const val PACKAGE_ID = "gallery.memories"
        private const val WORK_NAME_AUTO = "MemoriesWidgetAutoUpdate"

        /** Probability (0.0–1.0) of showing an On-This-Day photo when candidates exist. */
        private const val OTD_WEIGHT = 0.7

        private val MONTH_DAY_FORMAT = DateTimeFormatter.ofPattern("MM-dd")
        private val DISPLAY_DATE_FORMAT = DateTimeFormatter.ofPattern("MMMM d, yyyy", Locale.getDefault())
    }
}
