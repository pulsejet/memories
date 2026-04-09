package gallery.memories.wear.tile

import android.content.Context
import android.graphics.BitmapFactory
import android.util.Log
import androidx.wear.protolayout.ActionBuilders
import androidx.wear.protolayout.ColorBuilders.argb
import androidx.wear.protolayout.DimensionBuilders.dp
import androidx.wear.protolayout.DimensionBuilders.expand
import androidx.wear.protolayout.DimensionBuilders.sp
import androidx.wear.protolayout.DimensionBuilders.wrap
import androidx.wear.protolayout.LayoutElementBuilders
import androidx.wear.protolayout.LayoutElementBuilders.CONTENT_SCALE_MODE_CROP
import androidx.wear.protolayout.LayoutElementBuilders.FONT_WEIGHT_BOLD
import androidx.wear.protolayout.LayoutElementBuilders.HORIZONTAL_ALIGN_CENTER
import androidx.wear.protolayout.LayoutElementBuilders.TEXT_ALIGN_CENTER
import androidx.wear.protolayout.LayoutElementBuilders.TEXT_OVERFLOW_ELLIPSIZE
import androidx.wear.protolayout.LayoutElementBuilders.VERTICAL_ALIGN_BOTTOM
import androidx.wear.protolayout.ModifiersBuilders
import androidx.wear.protolayout.ResourceBuilders
import androidx.wear.protolayout.TimelineBuilders
import androidx.wear.tiles.EventBuilders
import androidx.wear.tiles.RequestBuilders
import androidx.wear.tiles.TileBuilders
import androidx.wear.tiles.TileService
import com.google.common.util.concurrent.Futures
import com.google.common.util.concurrent.ListenableFuture
import gallery.memories.wear.R
import java.io.ByteArrayOutputStream

/**
 * Wear OS Tile that displays a random photo from Nextcloud Memories.
 *
 * The photo is refreshed every 10 minutes by [PhotoRefreshWorker].
 * Tapping the tile triggers an immediate refresh.
 *
 * Layout (optimised for round watches):
 * - Full-bleed photo background
 * - Centered bottom overlay: label, date, and refresh icon stacked vertically
 * - If no photo is cached, a centered prompt to open settings
 */
class MemoriesPhotoTileService : TileService() {

    companion object {
        private const val TAG = "MemoriesTile"
        private const val RESOURCE_PHOTO = "photo"
        private const val RESOURCE_PHOTO_VERSION = "photo_v"
        private const val RESOURCE_REFRESH_ICON = "refresh_icon"
        private const val ACTION_REFRESH = "REFRESH"

        // Semi-transparent background for label overlay pill
        private const val OVERLAY_BG = 0xAA000000.toInt()     // ~67% black

        /** Request the system to update this tile. */
        fun requestTileUpdate(context: Context) {
            getUpdater(context).requestUpdate(MemoriesPhotoTileService::class.java)
        }
    }

    override fun onTileRequest(requestParams: RequestBuilders.TileRequest): ListenableFuture<TileBuilders.Tile> {
        val photoFile = PhotoRefreshWorker.getPhotoFile(this)
        val hasPhoto = photoFile.exists() && photoFile.length() > 0

        // Build a version string that changes when the photo changes
        val metaPrefs = getSharedPreferences(PhotoRefreshWorker.META_PREFS, MODE_PRIVATE)
        val lastRefresh = metaPrefs.getLong(PhotoRefreshWorker.KEY_LAST_REFRESH, 0)
        val resourceVersion = "$RESOURCE_PHOTO_VERSION$lastRefresh"

        val layout = if (hasPhoto) {
            val labelText = metaPrefs.getString(PhotoRefreshWorker.KEY_LABEL, null)
            val dateText = metaPrefs.getString(PhotoRefreshWorker.KEY_DATE, null)
            buildPhotoLayout(labelText, dateText)
        } else {
            buildEmptyLayout()
        }

        val timeline = TimelineBuilders.Timeline.Builder()
            .addTimelineEntry(
                TimelineBuilders.TimelineEntry.Builder()
                    .setLayout(
                        LayoutElementBuilders.Layout.Builder()
                            .setRoot(layout)
                            .build()
                    )
                    .build()
            )
            .build()

        val tile = TileBuilders.Tile.Builder()
            .setResourcesVersion(resourceVersion)
            .setTileTimeline(timeline)
            .setFreshnessIntervalMillis(PhotoRefreshWorker.REFRESH_INTERVAL_MINUTES * 60 * 1000)
            .build()

        return Futures.immediateFuture(tile)
    }

    override fun onTileResourcesRequest(requestParams: RequestBuilders.ResourcesRequest): ListenableFuture<ResourceBuilders.Resources> {
        val builder = ResourceBuilders.Resources.Builder()
            .setVersion(requestParams.version)

        // Load the cached photo as an image resource
        val photoFile = PhotoRefreshWorker.getPhotoFile(this)
        if (photoFile.exists() && photoFile.length() > 0) {
            try {
                val bitmap = BitmapFactory.decodeFile(photoFile.absolutePath)
                if (bitmap != null) {
                    val baos = ByteArrayOutputStream()
                    bitmap.compress(android.graphics.Bitmap.CompressFormat.JPEG, 90, baos)
                    val imageData = baos.toByteArray()

                    builder.addIdToImageMapping(
                        RESOURCE_PHOTO,
                        ResourceBuilders.ImageResource.Builder()
                            .setInlineResource(
                                ResourceBuilders.InlineImageResource.Builder()
                                    .setData(imageData)
                                    .setWidthPx(bitmap.width)
                                    .setHeightPx(bitmap.height)
                                    .setFormat(ResourceBuilders.IMAGE_FORMAT_UNDEFINED)
                                    .build()
                            )
                            .build()
                    )
                    bitmap.recycle()
                }
            } catch (e: Exception) {
                Log.e(TAG, "Failed to load photo for tile resource", e)
            }
        }

        // Refresh icon (Unicode arrow drawn as inline PNG)
        addRefreshIcon(builder)

        return Futures.immediateFuture(builder.build())
    }

    override fun onTileEnterEvent(requestParams: EventBuilders.TileEnterEvent) {
        super.onTileEnterEvent(requestParams)
        PhotoRefreshWorker.schedule(this)
    }

    // ════════════════════════════════════════════════════════════════════════
    // Layouts
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Build the photo layout optimised for round watches:
     * - Full-bleed photo background
     * - Centered bottom overlay with label, date, and a small refresh icon
     * Tapping anywhere triggers a refresh.
     */
    private fun buildPhotoLayout(
        labelText: String?,
        dateText: String?,
    ): LayoutElementBuilders.LayoutElement {

        val refreshAction = ActionBuilders.LaunchAction.Builder()
            .setAndroidActivity(
                ActionBuilders.AndroidActivity.Builder()
                    .setPackageName(packageName)
                    .setClassName(RefreshTrampolineActivity::class.java.name)
                    .build()
            )
            .build()

        val clickable = ModifiersBuilders.Clickable.Builder()
            .setId(ACTION_REFRESH)
            .setOnClick(refreshAction)
            .build()

        // Full-tile photo (background layer)
        val photoImage = LayoutElementBuilders.Image.Builder()
            .setResourceId(RESOURCE_PHOTO)
            .setWidth(expand())
            .setHeight(expand())
            .setContentScaleMode(CONTENT_SCALE_MODE_CROP)
            .build()

        // Centered bottom overlay column: label + date + refresh icon
        val overlayColumn = LayoutElementBuilders.Column.Builder()
            .setWidth(wrap())
            .setHorizontalAlignment(HORIZONTAL_ALIGN_CENTER)

        if (labelText != null) {
            overlayColumn.addContent(
                LayoutElementBuilders.Text.Builder()
                    .setText(labelText)
                    .setMaxLines(1)
                    .setOverflow(TEXT_OVERFLOW_ELLIPSIZE)
                    .setMultilineAlignment(TEXT_ALIGN_CENTER)
                    .setFontStyle(
                        LayoutElementBuilders.FontStyle.Builder()
                            .setSize(sp(13f))
                            .setWeight(FONT_WEIGHT_BOLD)
                            .setColor(argb(0xFFFFFFFF.toInt()))
                            .build()
                    )
                    .build()
            )
        }

        if (dateText != null) {
            overlayColumn.addContent(
                LayoutElementBuilders.Text.Builder()
                    .setText(dateText)
                    .setMaxLines(1)
                    .setOverflow(TEXT_OVERFLOW_ELLIPSIZE)
                    .setMultilineAlignment(TEXT_ALIGN_CENTER)
                    .setFontStyle(
                        LayoutElementBuilders.FontStyle.Builder()
                            .setSize(sp(10f))
                            .setColor(argb(0xCCFFFFFF.toInt()))
                            .build()
                    )
                    .build()
            )
        }

        // Small refresh icon centered below the text
        overlayColumn.addContent(
            LayoutElementBuilders.Spacer.Builder()
                .setHeight(dp(4f))
                .build()
        )
        overlayColumn.addContent(
            LayoutElementBuilders.Image.Builder()
                .setResourceId(RESOURCE_REFRESH_ICON)
                .setWidth(dp(18f))
                .setHeight(dp(18f))
                .build()
        )

        // Wrap overlay in a box with semi-transparent pill background, centered at bottom
        val bottomOverlay = LayoutElementBuilders.Box.Builder()
            .setWidth(wrap())
            .setHeight(wrap())
            .setModifiers(
                ModifiersBuilders.Modifiers.Builder()
                    .setPadding(
                        ModifiersBuilders.Padding.Builder()
                            .setStart(dp(16f))
                            .setEnd(dp(16f))
                            .setTop(dp(6f))
                            .setBottom(dp(8f))
                            .build()
                    )
                    .setBackground(
                        ModifiersBuilders.Background.Builder()
                            .setColor(argb(OVERLAY_BG))
                            .setCorner(
                                ModifiersBuilders.Corner.Builder()
                                    .setRadius(dp(16f))
                                    .build()
                            )
                            .build()
                    )
                    .build()
            )
            .addContent(overlayColumn.build())
            .build()

        // Stack: photo → centered bottom overlay, entire tile is tappable
        return LayoutElementBuilders.Box.Builder()
            .setWidth(expand())
            .setHeight(expand())
            .setModifiers(
                ModifiersBuilders.Modifiers.Builder()
                    .setClickable(clickable)
                    .build()
            )
            .addContent(photoImage)
            .addContent(
                // Push overlay to bottom-center with safe margin for round screen
                LayoutElementBuilders.Box.Builder()
                    .setWidth(expand())
                    .setHeight(expand())
                    .setVerticalAlignment(VERTICAL_ALIGN_BOTTOM)
                    .setHorizontalAlignment(HORIZONTAL_ALIGN_CENTER)
                    .setModifiers(
                        ModifiersBuilders.Modifiers.Builder()
                            .setPadding(
                                ModifiersBuilders.Padding.Builder()
                                    .setBottom(dp(12f))
                                    .build()
                            )
                            .build()
                    )
                    .addContent(bottomOverlay)
                    .build()
            )
            .build()
    }

    /** Build the empty-state layout prompting the user to set up. */
    private fun buildEmptyLayout(): LayoutElementBuilders.LayoutElement {
        val openConfigAction = ActionBuilders.LaunchAction.Builder()
            .setAndroidActivity(
                ActionBuilders.AndroidActivity.Builder()
                    .setPackageName(packageName)
                    .setClassName("gallery.memories.wear.config.WearConfigActivity")
                    .build()
            )
            .build()

        val clickable = ModifiersBuilders.Clickable.Builder()
            .setId("open_config")
            .setOnClick(openConfigAction)
            .build()

        return LayoutElementBuilders.Box.Builder()
            .setWidth(expand())
            .setHeight(expand())
            .setModifiers(
                ModifiersBuilders.Modifiers.Builder()
                    .setClickable(clickable)
                    .build()
            )
            .addContent(
                LayoutElementBuilders.Column.Builder()
                    .setWidth(expand())
                    .setHorizontalAlignment(LayoutElementBuilders.HORIZONTAL_ALIGN_CENTER)
                    .addContent(
                        LayoutElementBuilders.Text.Builder()
                            .setText("Memories")
                            .setFontStyle(
                                LayoutElementBuilders.FontStyle.Builder()
                                    .setSize(sp(16f))
                                    .setColor(argb(0xFFFFFFFF.toInt()))
                                    .build()
                            )
                            .build()
                    )
                    .addContent(
                        LayoutElementBuilders.Spacer.Builder()
                            .setHeight(dp(8f))
                            .build()
                    )
                    .addContent(
                        LayoutElementBuilders.Text.Builder()
                            .setText("Tap to set up")
                            .setFontStyle(
                                LayoutElementBuilders.FontStyle.Builder()
                                    .setSize(sp(12f))
                                    .setColor(argb(0xFF999999.toInt()))
                                    .build()
                            )
                            .build()
                    )
                    .build()
            )
            .build()
    }

    // ════════════════════════════════════════════════════════════════════════
    // Refresh icon generation
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Programmatically render a refresh (↻) icon as a small PNG
     * so we don't need a drawable resource.
     */
    private fun addRefreshIcon(builder: ResourceBuilders.Resources.Builder) {
        val size = 64 // px — larger for better visibility on small tiles
        val bitmap = android.graphics.Bitmap.createBitmap(size, size, android.graphics.Bitmap.Config.ARGB_8888)
        val canvas = android.graphics.Canvas(bitmap)
        val cx = size / 2f
        val cy = size / 2f
        val radius = size * 0.34f

        val paint = android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG).apply {
            color = 0xFFFFFFFF.toInt()
            style = android.graphics.Paint.Style.STROKE
            strokeWidth = size * 0.08f
            strokeCap = android.graphics.Paint.Cap.ROUND
        }

        // Draw arc (circular arrow body) — 300° sweep
        val inset = cx - radius
        val rect = android.graphics.RectF(inset, inset, size - inset, size - inset)
        canvas.drawArc(rect, -30f, 300f, false, paint)

        // Draw arrowhead at the end of the arc (-30° on the circle)
        val arrowPaint = android.graphics.Paint(android.graphics.Paint.ANTI_ALIAS_FLAG).apply {
            color = 0xFFFFFFFF.toInt()
            style = android.graphics.Paint.Style.FILL
        }
        val tipAngle = Math.toRadians(-30.0)
        val tipX = cx + radius * Math.cos(tipAngle).toFloat()
        val tipY = cy + radius * Math.sin(tipAngle).toFloat()
        val arrowLen = size * 0.2f
        val path = android.graphics.Path()
        path.moveTo(tipX, tipY)
        path.lineTo(tipX - arrowLen * 0.4f, tipY - arrowLen)
        path.lineTo(tipX + arrowLen * 0.7f, tipY - arrowLen * 0.35f)
        path.close()
        canvas.drawPath(path, arrowPaint)

        val baos = ByteArrayOutputStream()
        bitmap.compress(android.graphics.Bitmap.CompressFormat.PNG, 100, baos)
        bitmap.recycle()

        builder.addIdToImageMapping(
            RESOURCE_REFRESH_ICON,
            ResourceBuilders.ImageResource.Builder()
                .setInlineResource(
                    ResourceBuilders.InlineImageResource.Builder()
                        .setData(baos.toByteArray())
                        .setWidthPx(size)
                        .setHeightPx(size)
                        .setFormat(ResourceBuilders.IMAGE_FORMAT_UNDEFINED)
                        .build()
                )
                .build()
        )
    }
}
