package gallery.memories.widget

import android.app.Activity
import android.appwidget.AppWidgetManager
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.EditText
import android.widget.RadioButton
import android.widget.RadioGroup
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.google.android.material.button.MaterialButton
import gallery.memories.R

/**
 * Configuration activity shown when the user adds a Memories widget.
 * Lets the user pick the photo-change interval before the widget is placed.
 */
class WidgetConfigActivity : Activity() {

    private var appWidgetId = AppWidgetManager.INVALID_APPWIDGET_ID

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // If the user backs out, the widget should not be placed
        setResult(RESULT_CANCELED)

        appWidgetId = intent?.extras?.getInt(
            AppWidgetManager.EXTRA_APPWIDGET_ID,
            AppWidgetManager.INVALID_APPWIDGET_ID
        ) ?: AppWidgetManager.INVALID_APPWIDGET_ID

        if (appWidgetId == AppWidgetManager.INVALID_APPWIDGET_ID) {
            finish()
            return
        }

        setContentView(R.layout.activity_widget_config)

        val intervalGroup = findViewById<RadioGroup>(R.id.interval_group)
        val customContainer = findViewById<View>(R.id.custom_input_container)
        val customInput = findViewById<EditText>(R.id.custom_minutes_input)
        val btnConfirm = findViewById<MaterialButton>(R.id.btn_confirm)

        // Show/hide custom input based on selection
        intervalGroup.setOnCheckedChangeListener { _, checkedId ->
            customContainer.visibility =
                if (checkedId == R.id.interval_custom) View.VISIBLE else View.GONE
        }

        btnConfirm.setOnClickListener {
            val minutes = when (intervalGroup.checkedRadioButtonId) {
                R.id.interval_5 -> 5L
                R.id.interval_15 -> 15L
                R.id.interval_25 -> 25L
                R.id.interval_custom -> {
                    val text = customInput.text.toString().trim()
                    val value = text.toLongOrNull()
                    if (value == null || value < 1) {
                        customInput.error = getString(R.string.widget_config_invalid)
                        return@setOnClickListener
                    }
                    value
                }
                else -> WidgetPrefs.DEFAULT_INTERVAL_MINUTES
            }

            // Save preference
            WidgetPrefs.setIntervalMinutes(this, appWidgetId, minutes)

            // Trigger an immediate widget update
            val request = OneTimeWorkRequestBuilder<WidgetWorker>().build()
            WorkManager.getInstance(this).enqueueUniqueWork(
                "MemoriesWidgetRefresh",
                ExistingWorkPolicy.REPLACE,
                request,
            )

            // Also schedule the batch cache refresh
            CacheRefreshWorker.schedule(this)

            // Return success
            val resultValue = Intent().putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, appWidgetId)
            setResult(RESULT_OK, resultValue)
            finish()
        }
    }
}
