package gallery.memories.widget

import SecureStorage
import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.util.Base64
import android.util.Log
import androidx.annotation.OptIn
import androidx.media3.common.util.UnstableApi
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.security.SecureRandom
import java.security.cert.X509Certificate
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLContext
import javax.net.ssl.X509TrustManager
import kotlin.random.Random

/**
 * Periodic worker that pre-fetches a batch of photos into the widget cache every 5 minutes.
 * This ensures the cache always has fresh, random photos so manual refreshes feel instant and varied.
 */
@OptIn(UnstableApi::class)
class CacheRefreshWorker(
    private val context: Context,
    workerParams: WorkerParameters,
) : CoroutineWorker(context, workerParams) {

    override suspend fun doWork(): Result {
        return try {
            refreshCache()
            Result.success()
        } catch (e: Exception) {
            Log.e(TAG, "Cache refresh failed", e)
            Result.success() // Don't retry — will run again on next period
        }
    }

    private fun refreshCache() {
        val store = SecureStorage(context)
        val cred = store.getCredentials() ?: run {
            Log.d(TAG, "No credentials — skipping cache refresh")
            return
        }

        val client = buildOkHttpClient(cred.trustAll)
        val authHeader = "Basic ${Base64.encodeToString(
            "${cred.username}:${cred.token}".toByteArray(), Base64.NO_WRAP
        )}"

        // Fetch days list
        val daysArray = fetchDays(client, cred.url, authHeader) ?: return
        if (daysArray.length() == 0) return

        // Fetch BATCH_SIZE random photos
        var fetched = 0
        val maxAttempts = BATCH_SIZE * 3 // allow some failures
        var attempts = 0

        while (fetched < BATCH_SIZE && attempts < maxAttempts) {
            attempts++
            try {
                val (dayId, isOtd) = selectDay(daysArray)
                val fileId = fetchRandomPhotoId(client, cred.url, authHeader, dayId) ?: continue

                // Build metadata
                val dayDate = LocalDate.ofEpochDay(dayId)
                val labelText: String
                val dateText: String?
                val currentYear = LocalDate.now().year

                if (isOtd) {
                    labelText = "On this day"
                    val diff = currentYear - dayDate.year
                    dateText = when {
                        diff == 1 -> "1 year ago"
                        diff > 1 -> "$diff years ago"
                        else -> null
                    }
                } else {
                    val labels = arrayOf("From your memories", "Throwback", "Remember this?", "Rediscover")
                    labelText = labels.random()
                    dateText = dayDate.format(DateTimeFormatter.ofPattern("MMMM d, yyyy"))
                }

                // Location
                val locationText = fetchServerLocation(client, cred.url, authHeader, fileId)

                // Download preview
                val bitmap = downloadPreview(client, cred.url, authHeader, fileId) ?: continue

                val photoUri = "#v/$dayId/$fileId"
                cacheImage(bitmap, "batch_$fileId", labelText, dateText, locationText, photoUri)

                fetched++
                Log.d(TAG, "Cached batch photo $fetched/$BATCH_SIZE: fileId=$fileId")
            } catch (e: Exception) {
                Log.w(TAG, "Failed to fetch batch photo", e)
            }
        }

        Log.d(TAG, "Cache refresh complete: $fetched photos fetched")
    }

    // -- Server helpers (mirrored from WidgetWorker, simplified) ----------

    private fun fetchDays(client: OkHttpClient, baseUrl: String, authHeader: String): JSONArray? {
        return client.newCall(buildApiRequest(baseUrl, "api/days", authHeader))
            .execute().use { response ->
                if (response.code != 200) return null
                JSONArray(response.body.string())
            }
    }

    private fun selectDay(daysArray: JSONArray): Pair<Long, Boolean> {
        val today = LocalDate.now()
        val todayMd = today.format(DateTimeFormatter.ofPattern("MM-dd"))

        val otd = (0 until daysArray.length())
            .map { daysArray.getJSONObject(it) }
            .filter {
                val d = LocalDate.ofEpochDay(it.getLong("dayid"))
                d.format(DateTimeFormatter.ofPattern("MM-dd")) == todayMd && d.year != today.year
            }

        if (otd.isNotEmpty() && Random.nextDouble() < 0.7) {
            return otd.random().getLong("dayid") to true
        }

        val idx = (0 until daysArray.length()).random()
        return daysArray.getJSONObject(idx).getLong("dayid") to false
    }

    private fun fetchRandomPhotoId(
        client: OkHttpClient, baseUrl: String, authHeader: String, dayId: Long,
    ): Long? {
        val arr = client.newCall(buildApiRequest(baseUrl, "api/days/$dayId", authHeader))
            .execute().use { response ->
                if (response.code != 200) return null
                JSONArray(response.body.string())
            }
        if (arr.length() == 0) return null
        return arr.getJSONObject((0 until arr.length()).random()).getLong("fileid")
    }

    private fun fetchServerLocation(
        client: OkHttpClient, baseUrl: String, authHeader: String, fileId: Long,
    ): String? {
        return try {
            client.newCall(buildApiRequest(baseUrl, "api/image/info/$fileId", authHeader))
                .execute().use { response ->
                    if (response.code != 200) return null
                    val json = JSONObject(response.body.string())
                    val raw = json.optString("address", "").ifBlank { null }
                    raw?.let { simplifyAddress(it) }
                }
        } catch (e: Exception) { null }
    }

    private fun downloadPreview(
        client: OkHttpClient, baseUrl: String, authHeader: String, fileId: Long,
    ): Bitmap? {
        val bytes = client.newCall(
            buildApiRequest(baseUrl, "api/image/preview/$fileId?x=1024&y=1024", authHeader)
        ).execute().use { response ->
            if (response.code != 200) return null
            response.body.bytes()
        }
        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size)
    }

    private fun simplifyAddress(address: String): String {
        val parts = address.split(",").map { it.trim() }.filter { it.isNotBlank() }
        return when {
            parts.size <= 2 -> parts.joinToString(", ")
            else -> "${parts.first()}, ${parts.last()}"
        }
    }

    // -- Cache / HTTP helpers ---------------------------------------------

    private fun cacheImage(
        bitmap: Bitmap, tag: String,
        labelText: String?, dateText: String?, locationText: String?, photoUri: String?,
    ) {
        val dir = File(context.filesDir, CACHE_DIR).also { if (!it.exists()) it.mkdirs() }
        val baseName = "widget_${System.currentTimeMillis()}_${tag.hashCode()}"

        File(dir, "$baseName.jpg").outputStream().use { out ->
            bitmap.compress(Bitmap.CompressFormat.JPEG, 85, out)
        }

        val meta = JSONObject().apply {
            put("labelText", labelText ?: JSONObject.NULL)
            put("dateText", dateText ?: JSONObject.NULL)
            put("locationText", locationText ?: JSONObject.NULL)
            put("photoUri", photoUri ?: JSONObject.NULL)
        }
        File(dir, "$baseName.json").writeText(meta.toString())

        // Prune old files
        val files = dir.listFiles()
            ?.filter { it.name.startsWith("widget_") && it.extension == "jpg" }
            ?.sortedByDescending { it.lastModified() }
            ?: return

        if (files.size > MAX_CACHED) {
            files.drop(MAX_CACHED).forEach { f ->
                f.delete()
                File(f.absolutePath.replace(".jpg", ".json")).delete()
            }
        }
    }

    private fun buildOkHttpClient(trustAll: Boolean): OkHttpClient {
        val builder = OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(15, TimeUnit.SECONDS)

        if (trustAll) {
            val tm = object : X509TrustManager {
                override fun checkClientTrusted(chain: Array<X509Certificate>, t: String) {}
                override fun checkServerTrusted(chain: Array<X509Certificate>, t: String) {}
                override fun getAcceptedIssuers(): Array<X509Certificate> = arrayOf()
            }
            val sc = SSLContext.getInstance("TLS").apply { init(null, arrayOf(tm), SecureRandom()) }
            builder.sslSocketFactory(sc.socketFactory, tm).hostnameVerifier { _, _ -> true }
        }

        return builder.build()
    }

    private fun buildApiRequest(baseUrl: String, path: String, authHeader: String): Request =
        Request.Builder()
            .url("$baseUrl$path")
            .header("Authorization", authHeader)
            .header("User-Agent", "MemoriesNative/1.0")
            .header("OCS-APIRequest", "true")
            .header("X-Requested-With", "gallery.memories")
            .get()
            .build()

    companion object {
        private const val TAG = "CacheRefreshWorker"
        private const val CACHE_DIR = "widget_cache"
        private const val MAX_CACHED = 20
        private const val BATCH_SIZE = 5
        private const val WORK_NAME = "MemoriesWidgetCacheRefresh"
        private const val REFRESH_INTERVAL_MINUTES = 5L

        /** Schedule the periodic cache refresh. Safe to call multiple times. */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<CacheRefreshWorker>(
                REFRESH_INTERVAL_MINUTES, TimeUnit.MINUTES,
            ).build()

            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }

        /** Cancel the periodic cache refresh. */
        fun cancel(context: Context) {
            WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
        }
    }
}
