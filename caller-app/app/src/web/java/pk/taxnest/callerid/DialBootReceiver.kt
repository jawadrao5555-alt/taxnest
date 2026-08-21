package pk.taxnest.callerid

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Task 1381 — phone restart ya app update ke baad dial-watch dobara chalu.
 *
 * BOOT_COMPLETED aur MY_PACKAGE_REPLACED dono Android 12+ ki background-FGS
 * pabandi se mustasna hain, phir bhi sab kuch try/catch mein hai: service na
 * chal sake to app ki koi bhi screen khulte hi CallerApp use chala deta hai.
 */
class DialBootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        val a = intent?.action ?: return
        if (a != Intent.ACTION_BOOT_COMPLETED && a != Intent.ACTION_MY_PACKAGE_REPLACED) return
        try { DialWatchService.ensureRunning(context) } catch (e: Exception) { /* best-effort */ }
    }
}
