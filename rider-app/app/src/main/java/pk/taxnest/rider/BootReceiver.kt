package pk.taxnest.rider

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Phone rebooted mid-duty → resume tracking (duty flag persisted). */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        if (Prefs.token(context) != null && Prefs.duty(context)) {
            // v1.7.0: the watchdog does the same start, but when an OEM blocks
            // an FGS from boot it hands the rider a tap-to-resume notification
            // instead of failing silently.
            DutyWatchdog.ensureRunning(context)
        }
        // v1.4.0: delivery-check job survives reboot as long as rider is logged in.
        // v1.7.0: so does the point-sync/watchdog job.
        if (Prefs.token(context) != null) {
            DeliveryCheckWorker.schedule(context)
            SyncWorker.schedule(context)
        }
    }
}
