package pk.taxnest.rider

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat

/**
 * One-shot "near arrival" notification (Task #1508).
 *
 * Deduplication: fires at most once per (txn_id, assignment_revision) pair.
 * The seen set is stored in Prefs alongside the delivery seen-ids set so it
 * also gets cleared on clearToken (assignment change) and clearSession
 * (logout).  This means if the same bill is re-assigned to the same rider
 * (new assignment_revision) the notification fires again — which is correct.
 */
object ArrivalNotifier {
    private const val CHANNEL = "arrival_alerts"
    private const val NOTIF_ID = 3001

    /**
     * Call whenever the rider's GPS position is refreshed.
     * Thread-safe — may be called from TrackingService background thread.
     *
     * @param txnId the delivery bill id
     * @param assignmentRevision opaque assignment revision from the server
     * @param invoiceNumber human-readable bill number for notification text
     */
    @Synchronized
    fun checkAndNotify(
        c: Context,
        txnId: Int,
        assignmentRevision: String,
        invoiceNumber: String,
        riderLat: Double?,
        riderLng: Double?,
        accuracyM: Float?,
        meta: DestinationMeta
    ) {
        if (!ArrivalDetector.isNearArrival(riderLat, riderLng, accuracyM, meta)) return

        // Dedupe key: "txnId:revision" so re-assignment fires again.
        val key = "$txnId:$assignmentRevision"
        val seen = Prefs.seenArrivalIds(c)
        if (seen.contains(key)) return

        // Mark seen BEFORE posting to avoid races on repeated GPS callbacks.
        Prefs.setSeenArrivalIds(c, seen + key)
        postNotification(c, invoiceNumber)
    }

    private fun postNotification(c: Context, invoiceNumber: String) {
        try {
            val nm = c.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            if (Build.VERSION.SDK_INT >= 26) {
                nm.createNotificationChannel(
                    NotificationChannel(
                        CHANNEL,
                        c.getString(R.string.notif_arrival_channel),
                        NotificationManager.IMPORTANCE_HIGH
                    )
                )
            }
            val tap = PendingIntent.getActivity(
                c, 2,
                Intent(c, MainActivity::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            val notif = NotificationCompat.Builder(c, CHANNEL)
                .setSmallIcon(R.drawable.ic_rider)
                .setContentTitle(c.getString(R.string.notif_arrival_title))
                .setContentText(c.getString(R.string.notif_arrival_text, invoiceNumber))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(tap)
                .setDefaults(NotificationCompat.DEFAULT_ALL)
                .build()
            nm.notify(NOTIF_ID, notif)
        } catch (e: SecurityException) {
            // POST_NOTIFICATIONS not granted — silent.
        } catch (e: Exception) {
            // Notification failure must never crash tracking.
        }
    }
}
