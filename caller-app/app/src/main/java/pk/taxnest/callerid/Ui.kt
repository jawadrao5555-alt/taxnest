package pk.taxnest.callerid

import android.view.View
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat

/** Tiny shared UI helpers. */
object Ui {

    /**
     * Pad a screen's root by the system bars (Task 1346).
     *
     * Android 16 (targetSdk 36 = the Play build) draws every app edge-to-edge
     * and there is no opt-out: without this the header slides under the status
     * bar and the last button under the gesture bar.
     *
     * Website builds (targetSdk 34) are NOT edge-to-edge, so the insets they
     * receive here are zero and nothing moves — safe to call from every screen.
     * The layout's own padding is preserved and the insets are ADDED to it.
     */
    fun applyBarInsets(root: View) {
        val left = root.paddingLeft
        val top = root.paddingTop
        val right = root.paddingRight
        val bottom = root.paddingBottom
        ViewCompat.setOnApplyWindowInsetsListener(root) { v, insets ->
            val bars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars()
                    or WindowInsetsCompat.Type.displayCutout()
                    or WindowInsetsCompat.Type.ime()
            )
            v.setPadding(left + bars.left, top + bars.top, right + bars.right, bottom + bars.bottom)
            insets
        }
    }
}
