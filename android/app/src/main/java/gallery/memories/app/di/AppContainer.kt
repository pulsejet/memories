package gallery.memories.app.di

import android.net.Uri
import android.webkit.WebResourceResponse
import android.widget.Toast
import androidx.media3.common.util.UnstableApi
import gallery.memories.MainActivity
import gallery.memories.auth.AccountManager
import gallery.memories.bridge.ApiRouter
import gallery.memories.data.local.db.AppDatabase
import gallery.memories.data.local.media.ImageService
import gallery.memories.data.local.media.MediaDeleter
import gallery.memories.data.local.media.MediaObserver
import gallery.memories.data.local.media.MediaStoreDataSource
import gallery.memories.data.local.media.MediaSyncManager
import gallery.memories.data.local.media.PhotoMapper
import gallery.memories.data.local.prefs.FolderSettings
import gallery.memories.data.local.prefs.PreferencesStore
import gallery.memories.data.local.secure.SecureCredentialStore
import gallery.memories.data.remote.assets.AssetCache
import gallery.memories.data.remote.assets.AssetDownloader
import gallery.memories.data.remote.assets.AssetSyncCoordinator
import gallery.memories.data.remote.http.AuthState
import gallery.memories.data.remote.http.HttpClients
import gallery.memories.data.remote.http.NextcloudApi
import gallery.memories.server.LocalHttpServer
import gallery.memories.share.InAppDownloader
import gallery.memories.share.ShareManager
import gallery.memories.timeline.TimelineRepository
import gallery.memories.timeline.TimelineRepositoryImpl
import gallery.memories.ui.permissions.PermissionsManager
import gallery.memories.upload.UploadService

@UnstableApi
/**
 * Owns every app-scoped service and wires their dependencies.
 * Lives as long as the activity; [timeline] is late-initialized so the
 * media observer can reference it without a construction cycle.
 *
 * @param bridge handles in-app /api and /image requests for the local server.
 * @param onAllowMedia re-runs a full media sync after the user opts in.
 */
class AppContainer(
    private val activity: MainActivity,
    bridge: (method: String, url: Uri) -> WebResourceResponse,
    onAllowMedia: () -> Unit,
) {
    val prefs = PreferencesStore(activity)
    val secure = SecureCredentialStore(activity)
    val auth = AuthState()
    val clients = HttpClients(auth)
    val api = NextcloudApi(auth, clients)
    val cache = AssetCache(activity)
    val downloader = AssetDownloader(api)
    val assets = AssetSyncCoordinator(auth, api, cache, downloader, onError = { msg ->
        activity.runOnUiThread { Toast.makeText(activity, msg, Toast.LENGTH_LONG).show() }
    })

    private val db = AppDatabase.get(activity)
    val dao = db.photoDao()
    val dataSource = MediaStoreDataSource(activity)
    val mapper = PhotoMapper(activity)
    val syncManager = MediaSyncManager(activity, dao, dataSource, mapper, prefs)
    val folders = FolderSettings(dao, prefs)
    val deleter = MediaDeleter(activity)
    val permissions = PermissionsManager(activity, prefs).register()

    val timeline: TimelineRepository
    val observer: MediaObserver

    val image: ImageService
    val downloads = InAppDownloader(activity.applicationContext, auth, clients)
    val upload: UploadService
    val share: ShareManager
    val account = AccountManager(activity, auth, clients, api, assets, secure)
    val local = LocalHttpServer(activity, auth, clients, assets, bridge)
    val router: ApiRouter

    init {
        observer = MediaObserver(activity) { uris ->
            if (activity.isDestroyed || activity.isFinishing) return@MediaObserver
            if (timeline.syncDeltaDb() + timeline.evictUris(uris) > 0) activity.refreshTimeline()
        }
        timeline = TimelineRepositoryImpl(
            dao, dataSource, mapper, prefs, syncManager, deleter, folders, observer,
            onDatabaseChanged = { activity.refreshTimeline() },
            isAlive = { !activity.isDestroyed && !activity.isFinishing },
        )
        image = ImageService(activity, timeline)
        upload = UploadService(activity, timeline, auth, clients)
        share = ShareManager(activity, timeline, downloads)
        router = ApiRouter(account, timeline, image, upload, share, permissions, assets, onAllowMedia)
    }
}
