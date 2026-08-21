package pk.taxnest.callerid

import android.app.Activity
import android.app.Application
import android.os.Build
import android.os.Bundle

/**
 * Task 1381 — call back ka entry point. SIRF website builds (sim + plus).
 *
 * Yeh class `src/web/java` mein hai aur sirf sim/plus ke manifest mein
 * `android:name` ke tor par likhi gai hai — Play build ka source set aur
 * manifest bilkul nahi chhue gaye, wahan yeh code compile hi nahi hota.
 *
 * Kaam sirf itna: notification channels banao, aur jab bhi app ki koi screen
 * saamne aaye to dial-watch service chalti hui yakeeni banao. Service khud
 * signed-out halat mein foran ruk jati hai.
 */
class CallerApp : Application() {

    override fun onCreate() {
        super.onCreate()
        // Channels process ke shuru mein hi — service aur DialActivity dono ko
        // pehle se maujood channel chahiye.
        try { DialWatchService.createChannels(this) } catch (e: Exception) { /* best-effort */ }

        registerActivityLifecycleCallbacks(object : ActivityLifecycleCallbacksAdapter() {
            override fun onActivityResumed(activity: Activity) {
                // Foreground se start = Android 12+ ki background-FGS pabandi
                // ka mas'ala hi nahi. Boot/update par DialBootReceiver sambhalta hai.
                DialWatchService.ensureRunning(activity)
                val askedNow = askNotificationPermission(activity)
                if (!askedNow) warnIfOffersHidden(activity)
            }
        })
    }

    /**
     * Android 13+ par bina POST_NOTIFICATIONS ke tap-to-dial notification
     * dikhti hi nahi. Yeh Play Protect ki blocked chaar mein se nahi hai.
     * Ek app-launch mein ek hi baar poochte hain; do dafa mana karne par
     * Android khud dobara nahi dikhata.
     */
    private fun askNotificationPermission(activity: Activity): Boolean {
        if (Build.VERSION.SDK_INT < 33 || askedThisProcess) return false
        try {
            val perm = "android.permission.POST_NOTIFICATIONS"
            if (activity.checkSelfPermission(perm) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
                askedThisProcess = true
                activity.requestPermissions(arrayOf(perm), 9181)
                return true
            }
        } catch (e: Exception) { /* permission dialog optional hai */ }
        return false
    }

    /**
     * Notifications band hain (permission mana, ya settings/channel se off) to
     * POS number copy karwa deta hai — magar counter wale ko yahan bhi pata
     * chalna chahiye ke phone chup kyun hai, warna woh samajhta rahega ke app
     * kharab hai. Ek app-launch mein ek hi baar, aur sirf sign-in ki halat mein
     * (signed-out phone se koi call back hoti hi nahi).
     */
    private fun warnIfOffersHidden(activity: Activity) {
        if (warnedThisProcess || Prefs.token(activity) == null) return
        if (DialWatchService.offersVisible(activity)) return
        warnedThisProcess = true
        try {
            android.widget.Toast.makeText(
                activity,
                Lang.wrap(activity).getString(R.string.dial_notif_off_warn),
                android.widget.Toast.LENGTH_LONG
            ).show()
        } catch (e: Exception) { /* toast optional hai */ }
    }

    /** Sirf woh callback jo chahiye — baqi khali (Kotlin mein default nahi milte). */
    private abstract class ActivityLifecycleCallbacksAdapter : Application.ActivityLifecycleCallbacks {
        override fun onActivityCreated(a: Activity, b: Bundle?) {}
        override fun onActivityStarted(a: Activity) {}
        override fun onActivityPaused(a: Activity) {}
        override fun onActivityStopped(a: Activity) {}
        override fun onActivitySaveInstanceState(a: Activity, b: Bundle) {}
        override fun onActivityDestroyed(a: Activity) {}
    }

    companion object {
        @Volatile private var askedThisProcess = false
        @Volatile private var warnedThisProcess = false
    }
}
