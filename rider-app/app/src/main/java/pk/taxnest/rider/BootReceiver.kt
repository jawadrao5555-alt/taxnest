package pk.taxnest.rider

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import androidx.core.content.ContextCompat

/** Phone rebooted mid-duty → resume tracking (duty flag persisted). */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        if (Prefs.token(context) != null && Prefs.duty(context)) {
            try {
                ContextCompat.startForegroundService(context, Intent(context, TrackingService::class.java))
            } catch (e: Exception) {
                // FGS-from-boot blocked on some OEMs — rider reopens the app.
            }
        }
        // v1.4.0: delivery-check job survives reboot as long as rider is logged in.
        if (Prefs.token(context) != null) {
            DeliveryCheckWorker.schedule(context)
        }
    }
}
