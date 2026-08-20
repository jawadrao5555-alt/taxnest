package pk.taxnest.callerid

import android.app.Activity
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.provider.Settings
import android.widget.Toast

/**
 * Per-build permission plumbing — "plus" flavor (SIM + WhatsApp).
 *
 * MainActivity is build-agnostic: it only calls Detector.granted()/request()
 * and shows the flavor's own strings (perm_on / perm_off / perm_btn /
 * build_badge / howto_body live in each flavor's res/values/strings.xml).
 *
 * Plus build ko notification access chahiye (NotificationListenerService) —
 * yahi wo permission hai jis par Play Protect sideload install rok deti hai,
 * is liye yeh build sirf un shops ke liye hai jo Play Protect waqti tor par
 * band karne ko tayyar hon.
 */
object Detector {

    const val REQUEST_CODE = 7301   // sim build ke saath API barabar rakhne ke liye

    fun granted(ctx: Context): Boolean = try {
        val flat = Settings.Secure.getString(ctx.contentResolver, "enabled_notification_listeners") ?: ""
        flat.split(":").any { ComponentName.unflattenFromString(it)?.packageName == ctx.packageName }
    } catch (_: Exception) {
        false
    }

    fun request(activity: Activity) {
        try {
            activity.startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))
            Toast.makeText(activity, activity.getString(R.string.perm_toast), Toast.LENGTH_LONG).show()
        } catch (_: Exception) {}
    }
}
