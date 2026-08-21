package pk.taxnest.callerid

import android.app.Activity
import android.view.View
import android.widget.Button

/**
 * Self-update — PLAY build: deliberately does NOTHING.
 *
 * Task 1346. Google Play's Device and Network Abuse policy allows
 * `REQUEST_INSTALL_PACKAGES` only for a short list of use cases; "the app
 * updates itself" is not one of them. So the Play flavor:
 *   - removes the permission (`src/play/AndroidManifest.xml`, tools:node="remove")
 *   - does not compile `src/web/java` at all (no download / install code)
 *   - never calls `/version` — Play Store delivers updates.
 *
 * Website builds keep their own real `Updater` in `src/web/java`; nothing here
 * touches them.
 */
object Updater {

    /** false = Play Store is the only update channel for this build. */
    const val SELF_UPDATE = false

    fun attach(activity: Activity, updateRow: View, updateBtn: Button) {
        updateRow.visibility = View.GONE
    }
}
