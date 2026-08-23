package pk.taxnest.callerid

import android.Manifest
import android.app.Activity
import android.content.Context
import android.content.pm.PackageManager
import android.widget.Toast
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat

/**
 * Woh runtime permissions jo har build ki apni zaroorat NAHI hain, magar jis
 * build ne apni manifest mein maangi hain us mein number ka farq daalti hain
 * (v1.5.0).
 *
 * Aaj yeh sirf `plus` build ke kaam ki hai:
 *   READ_PHONE_STATE + READ_CALL_LOG → SIM ka asli number (PhoneStateReceiver)
 *   READ_CONTACTS                    → WhatsApp ke naam ka number contact list se
 *
 * Yahan koi `if (plusBuild)` nahi hai — jaan-boojh kar. Sirf wohi permission
 * maangi jati hai jo is APK ki manifest mein sach-much declare hai:
 *  • clean (sim) build → phone/call-log Detector pehle hi le chuka hota hai, is
 *    liye kuch baqi nahi bachta; contacts wahan declare hi nahi.
 *  • Play build       → teenon mein se koi declare nahi, yeh chup-chaap khali
 *    list dekh kar wapas aa jati hai.
 * Bina declare ki hui permission maangna Android khud rad kar deta hai
 * (dialog aata hi nahi), is liye asal guard manifest hi hai.
 *
 * Ek app-run mein ek hi baar poochhti hai: "Don't ask again" ke baad system
 * dialog waise bhi nahi khulta, aur har resume par poochhna sirf tang karta.
 */
object ExtraPerms {

    const val REQUEST_CODE = 7302

    private val WANTED = listOf(
        Manifest.permission.READ_PHONE_STATE,
        Manifest.permission.READ_CALL_LOG,
        Manifest.permission.READ_CONTACTS,
    )

    private var askedThisRun = false

    /** Is APK ne jo maangi hain un mein se jo abhi tak nahi mili. */
    fun missing(ctx: Context): List<String> {
        val declared = declared(ctx)
        return WANTED.filter {
            it in declared &&
                ContextCompat.checkSelfPermission(ctx, it) != PackageManager.PERMISSION_GRANTED
        }
    }

    /** Detector wali asal permission mil chuki ho, tab MainActivity yeh bulati hai. */
    fun maybeRequest(activity: Activity) {
        if (askedThisRun) return
        val missing = missing(activity)
        if (missing.isEmpty()) return
        askedThisRun = true
        try {
            Toast.makeText(activity, activity.getString(R.string.extra_perms_toast), Toast.LENGTH_LONG).show()
            ActivityCompat.requestPermissions(activity, missing.toTypedArray(), REQUEST_CODE)
        } catch (_: Exception) {
            // Permission dialog na khule to app waise hi chalti rahe.
        }
    }

    private fun declared(ctx: Context): Set<String> = try {
        ctx.packageManager
            .getPackageInfo(ctx.packageName, PackageManager.GET_PERMISSIONS)
            .requestedPermissions?.toSet() ?: emptySet()
    } catch (_: Exception) {
        emptySet()
    }
}
