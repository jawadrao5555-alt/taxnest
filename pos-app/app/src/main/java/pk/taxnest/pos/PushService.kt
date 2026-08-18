package pk.taxnest.pos

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * Instant POS push (v1.1.0, Task #1142) — naya order (cashier), order tayyar
 * (waiter), day-close summary (owner/manager).
 *
 * The server sends DATA-ONLY messages (no `notification` block) with a
 * generic {type: pos_event, event, title, body, nid} payload, so future
 * notification types need no APK update — the shell just renders title/body.
 * Tap opens MainActivity (the WebView restores wherever the user was).
 *
 * Framework Notification.Builder on purpose (no androidx UI deps — the shell
 * stays minimal). SecurityException (POST_NOTIFICATIONS denied on 13+) is
 * swallowed: the web app's in-page updates still cover the user.
 */
class PushService : FirebaseMessagingService() {

    companion object {
        private const val CHANNEL = "pos_alerts"
    }

    /** Firebase rotates tokens at will — keep the server copy current. */
    override fun onNewToken(token: String) {
        Push.upload(applicationContext, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val c = applicationContext
        // Logged out — a raced push must not notify a signed-out phone.
        if (!Push.isLoggedIn(c)) return
        val data = message.data
        if (data["type"] != "pos_event") return
        val title = data["title"] ?: return
        val body = data["body"] ?: ""
        try {
            val nm = c.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            if (Build.VERSION.SDK_INT >= 26) {
                nm.createNotificationChannel(
                    NotificationChannel(
                        CHANNEL,
                        c.getString(R.string.notif_channel_pos),
                        NotificationManager.IMPORTANCE_HIGH
                    )
                )
            }
            val tap = PendingIntent.getActivity(
                c, 1, Intent(c, MainActivity::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            @Suppress("DEPRECATION")
            val builder = if (Build.VERSION.SDK_INT >= 26) {
                Notification.Builder(c, CHANNEL)
            } else {
                Notification.Builder(c).setPriority(Notification.PRIORITY_HIGH)
                    .setDefaults(Notification.DEFAULT_ALL)
            }
            val notif = builder
                .setSmallIcon(R.drawable.ic_pos)
                .setContentTitle(title)
                .setContentText(body)
                .setStyle(Notification.BigTextStyle().bigText(body))
                .setAutoCancel(true)
                .setContentIntent(tap)
                .build()
            // Stable per-event id (server's nid) → a re-sent event replaces its
            // own notification instead of stacking duplicates; distinct events
            // stack normally.
            val nid = (data["nid"] ?: title + body).hashCode()
            nm.notify(nid, notif)
        } catch (e: SecurityException) {
            // POST_NOTIFICATIONS not granted (Android 13+) — web view still shows it.
        } catch (e: Exception) {
            // Notification failure must never crash the shell.
        }
    }
}
