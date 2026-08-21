package pk.taxnest.callerid

import android.app.Activity
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.provider.Settings
import android.widget.Toast

/**
 * Per-build permission plumbing — notification-access builds.
 *
 * Shared by `plus` (website, SIM + WhatsApp) and `play` (Play Store) through
 * `sourceSets` in app/build.gradle: `src/notif/java` is added to BOTH. Never
 * fork this file per flavor — the two builds must behave identically.
 *
 * MainActivity is build-agnostic: it only calls Detector.granted()/request()
 * and shows the flavor's own strings (perm_on / perm_off / perm_btn /
 * build_badge live in src/notif/res + each flavor's res/values/strings.xml).
 *
 * Task 1346 — request() ab SEEDHA settings nahi kholti: pehle prominent
 * disclosure screen aati hai (maqsad + kaunsa data + kahan jata hai + consent).
 * Google Play ki User Data policy is ke baghair app reject kar deti hai, aur
 * yehi baat website wali plus build par bhi theek hai. Is rasta ko kabhi
 * bypass na karein — openSettings() sirf disclosure ke "agree" se chalta hai.
 *
 * DO hisse, ek hi jawab: Android ki notification access ON honi chahiye AUR
 * disclosure par consent record hona chahiye. User seedha Android Settings se
 * access ON kar de (ya app ka data clear ho jaye) to consent nahi hota — aisi
 * soorat mein [granted] false rehti hai, app "band" dikhati hai, aur
 * CallListenerService kuch parhta hi nahi.
 */
object Detector {

    const val REQUEST_CODE = 7301   // sim build ke saath API barabar rakhne ke liye

    /** Android ki apni notification access (consent se qata-nazar). */
    fun listenerEnabled(ctx: Context): Boolean = try {
        val flat = Settings.Secure.getString(ctx.contentResolver, "enabled_notification_listeners") ?: ""
        flat.split(":").any { ComponentName.unflattenFromString(it)?.packageName == ctx.packageName }
    } catch (_: Exception) {
        false
    }

    /** Access + consent — app mein "ijazat mil gayi" ka matlab yehi hai. */
    fun granted(ctx: Context): Boolean =
        listenerEnabled(ctx) && Prefs.notifDisclosureAccepted(ctx)

    /** Prominent disclosure FIRST — the settings screen only opens after consent. */
    fun request(activity: Activity) {
        try {
            activity.startActivity(Intent(activity, NotificationDisclosureActivity::class.java))
        } catch (_: Exception) {
            // Disclosure screen kisi wajah se na khule to bhi bina consent ke
            // settings nahi kholte — user ko batayein aur chhor dein.
            Toast.makeText(activity, activity.getString(R.string.perm_toast), Toast.LENGTH_LONG).show()
        }
    }

    /** Called ONLY by NotificationDisclosureActivity after the user agrees. */
    fun openSettings(activity: Activity) {
        try {
            activity.startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))
            Toast.makeText(activity, activity.getString(R.string.perm_toast), Toast.LENGTH_LONG).show()
        } catch (_: Exception) {}
    }
}
