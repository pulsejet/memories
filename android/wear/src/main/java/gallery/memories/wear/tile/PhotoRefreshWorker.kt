package gallery.memories.wear.tile

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.util.Base64
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import gallery.memories.wear.R
import gallery.memories.wear.config.WearCredentialStore
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import java.io.File
import java.io.FileOutputStream
import java.security.SecureRandom
import java.security.cert.X509Certificate
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLContext
import javax.net.ssl.X509TrustManager
import kotlin.random.Random

/**
 * Background worker that fetches a random photo from the Nextcloud Memories
 * server and caches it for the tile to display.
 *
 * Runs every [REFRESH_INTERVAL_MINUTES] minutes (default 10) via WorkManager.
 * After each successful fetch, requests a tile update so the new photo
 * appears on the watch face.
 */
class PhotoRefreshWorker(
    private val context: Context,
    workerParams: WorkerParameters,
) : CoroutineWorker(context, workerParams) {

    override suspend fun doWork(): Result {
        val store = WearCredentialStore(context)
        val cred = store.get() ?: run {
            Log.d(TAG, "No credentials configured")
            return Result.failure()
        }

        val client = buildOkHttpClient(cred.trustAll)
        val authHeader = buildAuthHeader(cred.username, cred.password)

        return try {
            val success = fetchAndCachePhoto(client, cred.url, authHeader)
            if (success) {
                // Request tile update so the new photo appears
                MemoriesPhotoTileService.requestTileUpdate(context)
                Result.success()
            } else {
                Result.retry()
            }
        } catch (e: Exception) {
            Log.e(TAG, "Photo fetch failed", e)
            Result.retry()
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Photo fetching
    // ════════════════════════════════════════════════════════════════════════

    private fun fetchAndCachePhoto(
        client: OkHttpClient,
        baseUrl: String,
        authHeader: String,
    ): Boolean {
        // 1. Fetch days list
        val daysArray = client.newCall(
            buildApiRequest(baseUrl, "api/days", authHeader)
        ).execute().use { resp ->
            if (resp.code != 200) {
                Log.w(TAG, "/api/days returned ${resp.code}")
                return false
            }
            JSONArray(resp.body.string())
        }

        if (daysArray.length() == 0) {
            Log.w(TAG, "Server has no days")
            return false
        }

        // 2. Select a day (weighted toward On-This-Day)
        val (dayId, isOtd) = selectDay(daysArray)

        // 3. Pick a random photo from that day
        val photosArray = client.newCall(
            buildApiRequest(baseUrl, "api/days/$dayId", authHeader)
        ).execute().use { resp ->
            if (resp.code != 200) return false
            JSONArray(resp.body.string())
        }

        if (photosArray.length() == 0) return false

        val idx = (0 until photosArray.length()).random()
        val fileId = photosArray.getJSONObject(idx).getLong("fileid")

        // 4. Download preview
        val previewBytes = client.newCall(
            buildApiRequest(baseUrl, "api/image/preview/$fileId?x=$PREVIEW_SIZE&y=$PREVIEW_SIZE", authHeader)
        ).execute().use { resp ->
            if (resp.code != 200) {
                Log.w(TAG, "Preview for $fileId returned ${resp.code}")
                return false
            }
            resp.body.bytes()
        }

        val bitmap = BitmapFactory.decodeByteArray(previewBytes, 0, previewBytes.size)
            ?: return false

        // 5. Save to cache file
        val cacheFile = getPhotoFile(context)
        cacheFile.parentFile?.mkdirs()
        FileOutputStream(cacheFile).use { out ->
            bitmap.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY, out)
        }

        // 6. Compute label & date text
        val dayDate = LocalDate.ofEpochDay(dayId)
        val (labelText, dateText) = buildLabelAndDate(isOtd, dayDate)

        // 7. Save metadata
        val metaPrefs = context.getSharedPreferences(META_PREFS, Context.MODE_PRIVATE)
        metaPrefs.edit()
            .putLong(KEY_LAST_REFRESH, System.currentTimeMillis())
            .putLong(KEY_FILE_ID, fileId)
            .putLong(KEY_DAY_ID, dayId)
            .putBoolean(KEY_IS_OTD, isOtd)
            .putString(KEY_LABEL, labelText)
            .putString(KEY_DATE, dateText)
            .apply()

        Log.d(TAG, "Fetched photo fileId=$fileId from day=$dayId (OTD=$isOtd), ${bitmap.width}x${bitmap.height}")
        return true
    }

    // ════════════════════════════════════════════════════════════════════════
    // Day selection (same OTD logic as phone widget)
    // ════════════════════════════════════════════════════════════════════════

    private fun selectDay(daysArray: JSONArray): Pair<Long, Boolean> {
        val today = java.time.LocalDate.now()
        val todayMonthDay = today.format(MONTH_DAY_FORMAT)

        val otdCandidates = (0 until daysArray.length())
            .map { daysArray.getJSONObject(it) }
            .filter { dayObj ->
                val dayDate = java.time.LocalDate.ofEpochDay(dayObj.getLong("dayid"))
                dayDate.format(MONTH_DAY_FORMAT) == todayMonthDay && dayDate.year != today.year
            }

        if (otdCandidates.isNotEmpty() && Random.nextDouble() < OTD_WEIGHT) {
            val chosen = otdCandidates.random()
            return chosen.getLong("dayid") to true
        }

        val idx = (0 until daysArray.length()).random()
        return daysArray.getJSONObject(idx).getLong("dayid") to false
    }

    // ════════════════════════════════════════════════════════════════════════
    // Label & date helpers (same logic as phone widget)
    // ════════════════════════════════════════════════════════════════════════

    private fun buildLabelAndDate(
        isOnThisDay: Boolean,
        photoDate: LocalDate,
        currentYear: Int = LocalDate.now().year,
    ): Pair<String, String?> {
        if (isOnThisDay) {
            val diff = currentYear - photoDate.year
            val dateText = when {
                diff == 1 -> context.getString(R.string.widget_one_year_ago)
                diff > 1  -> context.getString(R.string.widget_years_ago, diff)
                else      -> null
            }
            return context.getString(R.string.widget_on_this_day) to dateText
        }
        return getRandomMemoryLabel() to photoDate.format(DISPLAY_DATE_FORMAT)
    }

    private fun getRandomMemoryLabel(): String {
        val labels = intArrayOf(
            R.string.widget_from_memories,
            R.string.widget_throwback,
            R.string.widget_remember_this,
            R.string.widget_rediscover,
        )
        return context.getString(labels.random())
    }

    // ════════════════════════════════════════════════════════════════════════
    // HTTP helpers
    // ════════════════════════════════════════════════════════════════════════

    private fun buildAuthHeader(username: String, password: String): String =
        "Basic ${Base64.encodeToString("$username:$password".toByteArray(), Base64.NO_WRAP)}"

    private fun buildApiRequest(baseUrl: String, path: String, authHeader: String): Request =
        Request.Builder()
            .url("$baseUrl$path")
            .header("Authorization", authHeader)
            .header("User-Agent", USER_AGENT)
            .header("OCS-APIRequest", "true")
            .header("X-Requested-With", PACKAGE_ID)
            .get()
            .build()

    private fun buildOkHttpClient(trustAll: Boolean): OkHttpClient {
        val builder = OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)

        if (trustAll) {
            val tm = object : X509TrustManager {
                override fun checkClientTrusted(c: Array<X509Certificate>, t: String) {}
                override fun checkServerTrusted(c: Array<X509Certificate>, t: String) {}
                override fun getAcceptedIssuers(): Array<X509Certificate> = arrayOf()
            }
            val sc = SSLContext.getInstance("TLS").apply { init(null, arrayOf(tm), SecureRandom()) }
            builder.sslSocketFactory(sc.socketFactory, tm)
                .hostnameVerifier { _, _ -> true }
        }

        return builder.build()
    }

    // ════════════════════════════════════════════════════════════════════════
    // Constants & companions
    // ════════════════════════════════════════════════════════════════════════

    companion object {
        private const val TAG = "PhotoRefreshWorker"
        private const val WORK_NAME_PERIODIC = "MemoriesWearPhotoRefresh"
        private const val WORK_NAME_IMMEDIATE = "MemoriesWearPhotoRefreshNow"

        private const val PREVIEW_SIZE = 400
        private const val JPEG_QUALITY = 85
        private const val OTD_WEIGHT = 0.7

        const val META_PREFS = "photo_meta"
        const val KEY_LAST_REFRESH = "last_refresh"
        const val KEY_FILE_ID = "file_id"
        const val KEY_DAY_ID = "day_id"
        const val KEY_IS_OTD = "is_otd"
        const val KEY_LABEL = "label_text"
        const val KEY_DATE = "date_text"

        const val REFRESH_INTERVAL_MINUTES = 10L

        private const val USER_AGENT = "MemoriesWear/1.0"
        private const val PACKAGE_ID = "gallery.memories.wear"
        private const val CACHE_FILE_NAME = "current_tile_photo.jpg"

        private val MONTH_DAY_FORMAT = java.time.format.DateTimeFormatter.ofPattern("MM-dd")
        private val DISPLAY_DATE_FORMAT = DateTimeFormatter.ofPattern("MMMM d, yyyy")

        /** Get the file where the current tile photo is stored. */
        fun getPhotoFile(context: Context): File =
            File(context.filesDir, CACHE_FILE_NAME)

        /** Schedule periodic refresh every [REFRESH_INTERVAL_MINUTES] minutes. */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<PhotoRefreshWorker>(
                REFRESH_INTERVAL_MINUTES, TimeUnit.MINUTES,
            ).build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME_PERIODIC,
                ExistingPeriodicWorkPolicy.UPDATE,
                request,
            )
            Log.d(TAG, "Scheduled periodic refresh every $REFRESH_INTERVAL_MINUTES min")
        }

        /** Trigger an immediate one-shot refresh. */
        fun refreshNow(context: Context) {
            val request = OneTimeWorkRequestBuilder<PhotoRefreshWorker>().build()
            WorkManager.getInstance(context).enqueueUniqueWork(
                WORK_NAME_IMMEDIATE,
                ExistingWorkPolicy.REPLACE,
                request,
            )
        }

        /** Cancel all scheduled refreshes. */
        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME_PERIODIC)
        }
    }
}
