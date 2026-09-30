package gallery.memories.bridge

import android.net.Uri
import android.webkit.WebResourceResponse
import androidx.media3.common.util.UnstableApi
import gallery.memories.NativeX
import gallery.memories.auth.AccountManager
import gallery.memories.data.local.media.ImageService
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.share.ShareManager
import gallery.memories.timeline.TimelineRepository
import gallery.memories.ui.permissions.PermissionsManager
import gallery.memories.upload.UploadService
import org.json.JSONObject
import java.net.URLDecoder
import java.nio.charset.StandardCharsets

@UnstableApi
/**
 * Maps localhost bridge paths (/api/…, /image/…) to app services.
 * GET-only; the caller handles method gating and error mapping.
 *
 * Path segments are 1-indexed by construction (split keeps the leading
 * empty element): /api/days/123 → ["", "api", "days", "123"].
 */
class ApiRouter(
    private val account: AccountManager,
    private val timeline: TimelineRepository,
    private val image: ImageService,
    private val upload: UploadService,
    private val share: ShareManager,
    private val permissions: PermissionsManager,
    private val assets: AssetSyncCoordinator,
    private val onAllowMedia: () -> Unit,
) {
    /**
     * Routes one GET request. Segment indices are safe: every [NativeX.API]
     * pattern fixes the segment count before its branch reads parts[3]/parts[4].
     */
    @Throws(Exception::class)
    fun routerGet(url: Uri): WebResourceResponse {
        val path = url.path ?: return BridgeResponses.error()
        val parts = path.split("/")
        return when {
            path.matches(NativeX.API.LOGIN) -> {
                account.login(decode(parts[3]), url.getBooleanQueryParameter("trustAll", false))
                BridgeResponses.json(JSONObject(mapOf("message" to "ok")))
            }
            path.matches(NativeX.API.DAYS) -> BridgeResponses.json(timeline.getDays())
            path.matches(NativeX.API.DAY) -> BridgeResponses.json(timeline.getDay(parts[3].toLong()))
            path.matches(NativeX.API.IMAGE_INFO) -> BridgeResponses.json(timeline.getImageInfo(parts[4].toLong()))
            path.matches(NativeX.API.IMAGE_DELETE) -> BridgeResponses.json(
                timeline.delete(BridgeResponses.parseIds(parts[4]), url.getBooleanQueryParameter("dry", false)),
            )
            path.matches(NativeX.API.IMAGE_PREVIEW) -> {
                val x = url.getQueryParameter("x")?.toInt()
                val y = url.getQueryParameter("y")?.toInt()
                BridgeResponses.bytes(image.getPreview(parts[3].toLong(), x, y), "image/jpeg")
            }
            path.matches(NativeX.API.IMAGE_FULL) -> {
                val size = url.getQueryParameter("size")?.toInt()
                BridgeResponses.bytes(image.getFull(parts[3], size), "image/jpeg")
            }
            path.matches(NativeX.API.UPLOAD_LOCAL) -> {
                val auid = url.getQueryParameter("auid") ?: throw Exception("Missing auid")
                val filename = url.getQueryParameter("filename") ?: throw Exception("Missing filename")
                BridgeResponses.json(upload.upload(auid, filename))
            }
            path.matches(NativeX.API.SHARE_URL) -> BridgeResponses.json(share.shareUrl(decode(parts[4])))
            path.matches(NativeX.API.SHARE_BLOB) -> BridgeResponses.json(share.shareBlobs())
            path.matches(NativeX.API.CONFIG_ALLOW_MEDIA) -> {
                permissions.setAllowMedia(true)
                if (permissions.requestMediaPermissionSync()) onAllowMedia()
                BridgeResponses.json("done")
            }
            path.matches(NativeX.API.ASSETS_PROGRESS) -> BridgeResponses.json(assets.progressJson())
            else -> throw Exception("Path did not match any known API route: $path")
        }
    }

    private fun decode(segment: String): String = URLDecoder.decode(segment, StandardCharsets.UTF_8)
}
