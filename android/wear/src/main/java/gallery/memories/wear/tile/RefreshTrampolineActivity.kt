package gallery.memories.wear.tile

import android.app.Activity
import android.os.Bundle
import android.widget.Toast

/**
 * Invisible trampoline activity launched when the user taps the tile.
 * Triggers an immediate photo refresh and finishes.
 */
class RefreshTrampolineActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        PhotoRefreshWorker.refreshNow(this)
        Toast.makeText(this, "Refreshing photo…", Toast.LENGTH_SHORT).show()

        finish()
    }
}
