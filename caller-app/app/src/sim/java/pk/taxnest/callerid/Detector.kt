package pk.taxnest.callerid

import android.Manifest
import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.Settings
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat

/**
 * Per-build permission plumbing — "clean" (sim) flavor.
 *
 * MainActivity is build-agnostic: it only calls Detector.granted()/request()
 * and shows the flavor's own strings (perm_on / perm_off / perm_btn /
 * build_badge / howto_body live in each flavor's res/values/strings.xml).
 *
 * Clean build ko sirf do runtime permissions chahiyen:
 *   READ_PHONE_STATE — RINGING state ka pata chale
 *   READ_CALL_LOG    — Android 9+ par incoming number is ke baghair khali aata hai
 * Dono Play Protect ki blocked chaar mein NAHI hain.
 */
object Detector {

    const val REQUEST_CODE = 7301

    private val NEEDED = arrayOf(
        Manifest.permission.READ_PHONE_STATE,
        Manifest.permission.READ_CALL_LOG,
    )

    fun granted(ctx: Context): Boolean = NEEDED.all {
        ContextCompat.checkSelfPermission(ctx, it) == PackageManager.PERMISSION_GRANTED
    }

    fun request(activity: Activity) {
        // "Don't ask again" ke baad system dialog khulta hi nahi — us soorat
        // mein seedha app ki settings kholo warna button bekaar lagta hai.
        val blocked = Prefs.permAsked(activity) && NEEDED.none {
            ActivityCompat.shouldShowRequestPermissionRationale(activity, it)
        }
        if (blocked) {
            try {
                activity.startActivity(
                    Intent(
                        Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                        Uri.parse("package:" + activity.packageName)
                    )
                )
                return
            } catch (_: Exception) {
                // niche wala normal request try kar lo
            }
        }
        Prefs.setPermAsked(activity, true)
        try {
            ActivityCompat.requestPermissions(activity, NEEDED, REQUEST_CODE)
        } catch (_: Exception) {}
    }
}
