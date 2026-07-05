package gallery.memories

import android.annotation.SuppressLint
import android.content.ContentResolver
import android.content.Intent
import android.graphics.drawable.Drawable
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.MediaStore
import android.util.Size
import android.view.Menu
import android.view.MenuItem
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.media3.common.util.UnstableApi
import androidx.recyclerview.widget.RecyclerView
import androidx.viewpager2.widget.ViewPager2
import com.bumptech.glide.Glide
import com.bumptech.glide.request.target.CustomTarget
import com.bumptech.glide.request.transition.Transition
import com.github.chrisbanes.photoview.PhotoView
import gallery.memories.databinding.ActivityViewerBinding
import gallery.memories.databinding.PageViewerImageBinding
import gallery.memories.databinding.PageViewerVideoBinding
import gallery.memories.mapper.LocalMediaInfo
import gallery.memories.service.MediaResolverService
import gallery.memories.service.PlayerController
import gallery.memories.service.PermissionsService
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@UnstableApi
class ViewerActivity : AppCompatActivity() {
    companion object {
        val TAG: String = ViewerActivity::class.java.simpleName
        const val EXTRA_PENDING_PHOTO_HASH = "EXTRA_PENDING_PHOTO_HASH"
    }

    private lateinit var binding: ActivityViewerBinding
    private lateinit var mediaResolver: MediaResolverService
    private var permissionsService: PermissionsService? = null
    private var playerController: PlayerController? = null

    private var currentMediaInfo: LocalMediaInfo? = null
    private var mediaList: List<LocalMediaInfo>? = null
    private var isPickerMode: Boolean = false
    private var isSingleMode: Boolean = false

    private var deleteResultLauncher: ActivityResultLauncher<Intent>? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        binding = ActivityViewerBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Initialize services
        mediaResolver = MediaResolverService(this)
        permissionsService = PermissionsService(this).register()

        // Setup toolbar
        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        supportActionBar?.setDisplayShowHomeEnabled(true)

        // Setup ViewPager page change listener for toolbar auto-hide
        binding.viewPager.registerOnPageChangeCallback(object : ViewPager2.OnPageChangeCallback() {
            override fun onPageSelected(position: Int) {
                currentMediaInfo = mediaList?.getOrNull(position)
                invalidateOptionsMenu()
            }
        })

        // Setup delete launcher for API 30+
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            deleteResultLauncher = registerForActivityResult(
                ActivityResultContracts.StartIntentSenderForResult()
            ) { result ->
                if (result.resultCode == RESULT_OK) {
                    onMediaDeleted()
                }
            }
        }

        // Determine mode and handle intent
        handleIntent(intent)
    }

    override fun onDestroy() {
        super.onDestroy()
        playerController?.release()
    }

    override fun onSupportNavigateUp(): Boolean {
        finish()
        return true
    }

    override fun onCreateOptionsMenu(menu: Menu?): Boolean {
        menuInflater.inflate(R.menu.menu_viewer, menu)
        return true
    }

    override fun onPrepareOptionsMenu(menu: Menu?): Boolean {
        if (isPickerMode) {
            // Hide Share and Delete in picker mode
            menu?.findItem(R.id.action_share)?.isVisible = false
            menu?.findItem(R.id.action_delete)?.isVisible = false
            menu?.findItem(R.id.action_use_this)?.isVisible = true
        } else {
            // Hide "Use this" in non-picker mode
            menu?.findItem(R.id.action_use_this)?.isVisible = false
        }

        // Update FAB visibility
        updateFabVisibility()

        return super.onPrepareOptionsMenu(menu)
    }

    override fun onOptionsItemSelected(item: MenuItem): Boolean {
        val info = currentMediaInfo ?: return false

        return when (item.itemId) {
            R.id.action_share -> {
                shareMedia(info)
                true
            }

            R.id.action_delete -> {
                deleteMedia(info)
                true
            }

            R.id.action_use_this -> {
                if (isPickerMode) {
                    setPickerResult(info.uri)
                    finish()
                }
                true
            }

            else -> super.onOptionsItemSelected(item)
        }
    }

    private fun handleIntent(intent: Intent) {
        val uri = intent.data ?: return

        // Determine picker mode
        isPickerMode = intent.action == Intent.ACTION_PICK || intent.action == Intent.ACTION_GET_CONTENT

        // Resolve the URI to LocalMediaInfo
        CoroutineScope(Dispatchers.IO).launch {
            val mediaInfo = mediaResolver.resolveUri(uri) ?: run {
                withContext(Dispatchers.Main) {
                    Toast.makeText(this@ViewerActivity, "Failed to resolve media", Toast.LENGTH_SHORT)
                        .show()
                    finish()
                }
                return@launch
            }

            withContext(Dispatchers.Main) {
                currentMediaInfo = mediaInfo

                // Routing logic (from spec §5.1)
                if (isPickerMode) {
                    // MODE C: picker mode
                    setupPickerMode(mediaInfo)
                } else if (permissionsService?.hasAllowMedia() == true && mediaInfo.dayId != null) {
                    // MODE A: redirect to MainActivity
                    redirectToMainActivity(mediaInfo)
                } else {
                    // MODE B: native viewer
                    setupNativeViewer(mediaInfo)
                }
            }
        }
    }

    /**
     * MODE A: Redirect to MainActivity with photo hash
     */
    private fun redirectToMainActivity(info: LocalMediaInfo) {
        val hash = "#v/${info.dayId}/${info.localId}"
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra(EXTRA_PENDING_PHOTO_HASH, hash)
        }
        startActivity(intent)
        finish()
    }

    /**
     * MODE B: Native viewer setup
     */
    private fun setupNativeViewer(info: LocalMediaInfo) {
        isSingleMode = info.dayId == null

        // If single-mode, just show the one item
        if (isSingleMode) {
            currentMediaInfo = info
            val adapter = ViewerAdapter(listOf(info))
            binding.viewPager.adapter = adapter
        } else {
            // Load photos for this day and setup ViewPager
            loadPhotosForDay(info)
        }

        invalidateOptionsMenu()
    }

    /**
     * MODE C: Picker mode setup
     */
    private fun setupPickerMode(info: LocalMediaInfo) {
        isSingleMode = true
        currentMediaInfo = info
        val adapter = ViewerAdapter(listOf(info))
        binding.viewPager.adapter = adapter
        invalidateOptionsMenu()
    }

    private fun loadPhotosForDay(info: LocalMediaInfo) {
        if (info.dayId == null) {
            isSingleMode = true
            return
        }

        CoroutineScope(Dispatchers.IO).launch {
            try {
                val appDatabase = gallery.memories.dao.AppDatabase.getInstance(this@ViewerActivity)
                val photos = appDatabase.photoDao().getPhotosByDay(
                    info.dayId,
                    emptyList() // allBuckets - use empty for now
                )

                // Convert to LocalMediaInfo
                val mediaInfoList = photos.map { photo ->
                    LocalMediaInfo(
                        localId = photo.localId,
                        auid = photo.auid,
                        dayId = photo.dayId,
                        mimeType = if (photo.baseName.endsWith(".mp4")) "video/mp4" else "image/*",
                        uri = getUriForLocalId(photo.localId) ?: Uri.EMPTY,
                        isVideo = photo.baseName.endsWith(".mp4")
                    )
                }

                if (mediaInfoList.isEmpty()) {
                    withContext(Dispatchers.Main) {
                        Toast.makeText(this@ViewerActivity, "No media found for this day", Toast.LENGTH_SHORT)
                            .show()
                        finish()
                    }
                    return@launch
                }

                mediaList = mediaInfoList

                withContext(Dispatchers.Main) {
                    val index = mediaInfoList.indexOfFirst { it.localId == info.localId }
                    val adapter = ViewerAdapter(mediaInfoList)
                    binding.viewPager.adapter = adapter
                    binding.viewPager.setCurrentItem(index.coerceAtLeast(0), false)
                }
            } catch (e: Exception) {
                e.printStackTrace()
                withContext(Dispatchers.Main) {
                    Toast.makeText(this@ViewerActivity, "Error loading photos", Toast.LENGTH_SHORT).show()
                    finish()
                }
            }
        }
    }

    private fun getUriForLocalId(localId: Long): Uri? {
        try {
            val projection = arrayOf(MediaStore.Images.Media.DISPLAY_NAME)
            contentResolver.query(
                Uri.withAppendedPath(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, localId.toString()),
                projection,
                null,
                null,
                null
            )?.use { cursor ->
                if (cursor.moveToFirst()) {
                    return Uri.withAppendedPath(
                        MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
                        localId.toString()
                    )
                }
            }

            // Try videos
            contentResolver.query(
                Uri.withAppendedPath(MediaStore.Video.Media.EXTERNAL_CONTENT_URI, localId.toString()),
                projection,
                null,
                null,
                null
            )?.use { cursor ->
                if (cursor.moveToFirst()) {
                    return Uri.withAppendedPath(
                        MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
                        localId.toString()
                    )
                }
            }
        } catch (e: Exception) {
            e.printStackTrace()
        }
        return null
    }

    private fun shareMedia(info: LocalMediaInfo) {
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = info.mimeType
            putExtra(Intent.EXTRA_STREAM, info.uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        startActivity(Intent.createChooser(intent, null))
    }

    private fun deleteMedia(info: LocalMediaInfo) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            // Use modern trash API
            val request = MediaStore.createTrashRequest(contentResolver, listOf(info.uri), true)
            deleteResultLauncher?.launch(request.intentSender)
        } else {
            // Fallback for older APIs
            val deleted = contentResolver.delete(info.uri, null, null)
            if (deleted > 0) {
                onMediaDeleted()
            } else {
                Toast.makeText(this, "Failed to delete media", Toast.LENGTH_SHORT).show()
            }
        }
    }

    private fun onMediaDeleted() {
        if (isSingleMode || mediaList == null || mediaList!!.size <= 1) {
            finish()
        } else {
            // Remove from list and update pager
            val currentIndex = binding.viewPager.currentItem
            mediaList = (mediaList ?: emptyList()).filterIndexed { index, _ -> index != currentIndex }

            if (mediaList?.isEmpty() == true) {
                finish()
            } else {
                binding.viewPager.adapter = ViewerAdapter(mediaList ?: emptyList())
                binding.viewPager.setCurrentItem(
                    if (currentIndex > 0) currentIndex - 1 else 0,
                    false
                )
            }
        }
    }

    private fun setPickerResult(uri: Uri) {
        setResult(RESULT_OK, Intent().setData(uri))
    }

    private fun toggleToolbar() {
        val isVisible = binding.toolbar.visibility == View.VISIBLE
        binding.toolbar.visibility = if (isVisible) View.GONE else View.VISIBLE
    }

    private fun updateFabVisibility() {
        val info = currentMediaInfo ?: return
        // Check if logged in by checking SharedPreferences
        val prefs = getSharedPreferences(getString(R.string.preferences_key), 0)
        val isLoggedIn = prefs.getString("auth_header", null) != null

        binding.fabOpenInMemories.visibility =
            if (isPickerMode.not() && isLoggedIn && info.dayId != null) View.VISIBLE else View.GONE
        
        // Setup FAB click listener to redirect to MainActivity
        binding.fabOpenInMemories.setOnClickListener {
            redirectToMainActivity(info)
        }
    }

    /**
     * ViewPager2 adapter for showing photos/videos
     */
    private inner class ViewerAdapter(private val items: List<LocalMediaInfo>) :
        RecyclerView.Adapter<ViewHolder>() {

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
            return if (viewType == 0) {
                val binding = PageViewerImageBinding.inflate(layoutInflater, parent, false)
                ImageViewHolder(binding)
            } else {
                val binding = PageViewerVideoBinding.inflate(layoutInflater, parent, false)
                VideoViewHolder(binding)
            }
        }

        override fun onBindViewHolder(holder: ViewHolder, position: Int) {
            val item = items[position]
            currentMediaInfo = item
            holder.bind(item)
        }

        override fun getItemCount() = items.size

        override fun getItemViewType(position: Int): Int {
            return if (items[position].isVideo) 1 else 0
        }
    }

    private abstract inner class ViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        abstract fun bind(item: LocalMediaInfo)
    }

    private inner class ImageViewHolder(private val binding: PageViewerImageBinding) :
        ViewHolder(binding.root) {

        override fun bind(item: LocalMediaInfo) {
            // Load thumbnail first (off-screen)
            if (bindingAdapterPosition > binding.viewPager.currentItem + 1) {
                loadThumbnail(item, binding.photoView)
            } else {
                // Load full image for visible pages
                loadFullImage(item, binding.photoView)
            }
        }

        private fun loadThumbnail(info: LocalMediaInfo, photoView: PhotoView) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                try {
                    val thumbnail = contentResolver.loadThumbnail(info.uri, Size(512, 512), null)
                    photoView.setImageBitmap(thumbnail)
                } catch (e: Exception) {
                    e.printStackTrace()
                }
            }
        }

        private fun loadFullImage(info: LocalMediaInfo, photoView: PhotoView) {
            Glide.with(this@ViewerActivity)
                .asBitmap()
                .load(info.uri)
                .into(object : CustomTarget<android.graphics.Bitmap>() {
                    override fun onResourceReady(
                        resource: android.graphics.Bitmap,
                        transition: Transition<in android.graphics.Bitmap>?
                    ) {
                        photoView.setImageBitmap(resource)
                    }

                    override fun onLoadCleared(placeholder: Drawable?) {}
                })
        }
    }

    private inner class VideoViewHolder(private val binding: PageViewerVideoBinding) :
        ViewHolder(binding.root) {

        override fun bind(item: LocalMediaInfo) {
            // Setup video player
            if (playerController == null) {
                playerController = PlayerController(this@ViewerActivity)
            }

            playerController!!.buildAndBind(
                binding.playerView,
                arrayOf(item.uri),
                false,
                false
            )
        }
    }
}
