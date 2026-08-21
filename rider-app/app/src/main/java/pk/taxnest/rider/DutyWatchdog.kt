package pk.taxnest.rider

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat

/**
 * Duty watchdog (v1.7.0, Task #1359).
 *
 * The failure the owner saw: duty was ON, the phone's battery saver froze the
 * app, TrackingService died, and NOTHING noticed until the rider re-opened the
 * app at the shop — at which point the whole route uploaded in one burst.
 *
 * This object is the "is tracking actually alive?" check, called from every
 * place that can run while the app is closed (SyncWorker, push, boot) plus the
 * app's own resume.  Two outcomes:
 *  - we could restart the service  → tracking is back, notification cleared;
 *  - Android refused the background start (Android 12+ throws
 *    ForegroundServiceStartNotAllowedException) → we must NOT fail silently:
 *    the rider gets a tap-to-resume notification, and tapping it opens the app
 *    which restarts tracking from the foreground (always allowed).
 */
object DutyWatchdog {

    private const val CHANNEL = "duty_watchdog"
    private const val NOTIF_ID = 2002

    /** MainActivity intent extra — "start tracking as soon as you're up". */
    const val EXTRA_RESUME = "resume_duty"

    /**
     * Make sure tracking is running when duty is ON.
     * @return true when the service is (now) running, false when Android
     *         blocked the start and the rider was notified instead.
     * Safe to call from any thread / any context (worker, receiver, activity).
     */
    fun ensureRunning(c: Context): Boolean {
        val app = c.applicationContext
        if (Prefs.token(app) == null || !Prefs.duty(app)) {
            clearNotification(app)
            return false
        }
        if (TrackingService.running) {
            clearNotification(app)
            return true
        }
        return try {
            ContextCompat.startForegroundService(app, Intent(app, TrackingService::class.java))
            // NOTE: do not clear the notification here. The start is accepted
            // now but the foreground promotion happens moments later inside the
            // service and can still be refused (Android 14 location FGS). The
            // service clears the notification itself once it is really running,
            // and re-posts it if promotion failed.
            true
        } catch (e: Exception) {
            // Android 12+ background-start restriction, OEM block, or the
            // service crashed on start — tell the rider, never fail quietly.
            notifyResumeNeeded(app)
            false
        }
    }

    /** Tap-to-resume notification — the rider's one-tap fix. */
    fun notifyResumeNeeded(c: Context) {
        try {
            val nm = c.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            if (Build.VERSION.SDK_INT >= 26) {
                nm.createNotificationChannel(
                    NotificationChannel(
                        CHANNEL,
                        c.getString(R.string.notif_watchdog_channel),
                        NotificationManager.IMPORTANCE_HIGH
                    )
                )
            }
            val intent = Intent(c, MainActivity::class.java).apply {
                putExtra(EXTRA_RESUME, true)
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            }
            val tap = PendingIntent.getActivity(
                c, 2, intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            val notif = NotificationCompat.Builder(c, CHANNEL)
                .setSmallIcon(R.drawable.ic_rider)
                .setContentTitle(c.getString(R.string.notif_watchdog_title))
                .setContentText(c.getString(R.string.notif_watchdog_text))
                .setStyle(
                    NotificationCompat.BigTextStyle()
                        .bigText(c.getString(R.string.notif_watchdog_text))
                )
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setOngoing(true)      // duty is ON but dead — must not be swiped away
                .setAutoCancel(true)
                .setContentIntent(tap)
                .build()
            nm.notify(NOTIF_ID, notif)
        } catch (e: SecurityException) {
            // POST_NOTIFICATIONS not granted — the home screen warning covers it.
        } catch (e: Exception) {
            // A watchdog must never crash the caller (worker / push / boot).
        }
    }

    fun clearNotification(c: Context) {
        try {
            (c.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
                .cancel(NOTIF_ID)
        } catch (e: Exception) {}
    }
}
