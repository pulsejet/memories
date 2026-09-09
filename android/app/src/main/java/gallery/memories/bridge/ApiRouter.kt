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
import java.net.URLDecoder

@UnstableApi
/**
 * Maps localhost bridge paths (/api/…, /image/…) to app services.
 * GET-only; the caller handles method gating and error mapping.
 */
class ApiRouter(
    private val account: AccountManager,
    private val timeline: TimelineRepository,
    private val image: ImageService,
    private val share: ShareManager,
    private val permissions: PermissionsManager,
    private val assets: AssetSyncCoordinator,
    private val onAllowMedia: () -> Unit,
) {
    @Throws(Exception::class)
    fun routerGet(url: Uri): WebResourceResponse {
        val path = url.path ?: return BridgeResponses.error()
        val parts = path.split("/").toTypedArray()
        return if (path.matches(NativeX.API.LOGIN)) {
            account.login(URLDecoder.decode(parts[3], "UTF-8"), url.getBooleanQueryParameter("trustAll", false))
            BridgeResponses.json(mapOf("message" to "ok").let { org.json.JSONObject(it) })
        } else if (path.matches(NativeX.API.DAYS)) {
            BridgeResponses.json(timeline.getDays())
        } else if (path.matches(NativeX.API.DAY)) {
            BridgeResponses.json(timeline.getDay(parts[3].toLong()))
        } else if (path.matches(NativeX.API.IMAGE_INFO)) {
            BridgeResponses.json(timeline.getImageInfo(parts[4].toLong()))
        } else if (path.matches(NativeX.API.IMAGE_DELETE)) {
            BridgeResponses.json(timeline.delete(BridgeResponses.parseIds(parts[4]), url.getBooleanQueryParameter("dry", false)))
        } else if (path.matches(NativeX.API.IMAGE_PREVIEW)) {
            val x = url.getQueryParameter("x")?.toInt()
            val y = url.getQueryParameter("y")?.toInt()
            BridgeResponses.bytes(image.getPreview(parts[3].toLong(), x, y), "image/jpeg")
        } else if (path.matches(NativeX.API.IMAGE_FULL)) {
            val size = url.getQueryParameter("size")?.toInt()
            BridgeResponses.bytes(image.getFull(parts[3], size), "image/jpeg")
        } else if (path.matches(NativeX.API.SHARE_URL)) {
            BridgeResponses.json(share.shareUrl(URLDecoder.decode(parts[4], "UTF-8")))
        } else if (path.matches(NativeX.API.SHARE_BLOB)) {
            BridgeResponses.json(share.shareBlobs())
        } else if (path.matches(NativeX.API.CONFIG_ALLOW_MEDIA)) {
            permissions.setAllowMedia(true)
            if (permissions.requestMediaPermissionSync()) onAllowMedia()
            BridgeResponses.json("done")
        } else if (path.matches(NativeX.API.ASSETS_PROGRESS)) {
            BridgeResponses.json(assets.progressJson())
        } else {
            throw Exception("Path did not match any known API route: $path")
        }
    }
}
