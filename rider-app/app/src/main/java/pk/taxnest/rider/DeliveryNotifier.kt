package pk.taxnest.rider

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import org.json.JSONArray
import java.util.Locale

/**
 * New-delivery notifications (v1.4.0, owner request 7 Aug 2026 — the "Touseef
 * case": bills assigned days earlier, rider never knew because nothing pinged
 * his phone).  Dedupe by bill id via Prefs.seenDeliveryIds so each bill alerts
 * exactly once per assignment; the set is REPLACED with the current open list
 * on every pass, so delivered/returned bills drop out and it never grows.
 */
object DeliveryNotifier {
    private const val CHANNEL = "new_deliveries"
    private const val NOTIF_ID = 2001

    /** Safe to call from any thread. */
    fun process(c: Context, arr: JSONArray) {
        val current = LinkedHashSet<String>()
        val fresh = mutableListOf<String>()
        val seen = Prefs.seenDeliveryIds(c)
        for (i in 0 until arr.length()) {
            val item = arr.optJSONObject(i) ?: continue
            val id = item.optInt("id", 0).toString()
            current.add(id)
            if (!seen.contains(id)) {
                val inv = item.optString("invoice_number").ifBlank { "#$id" }
                val amt = String.format(Locale.US, "%,.0f", item.optDouble("amount", 0.0))
                fresh.add("$inv — Rs $amt")
            }
        }
        Prefs.setSeenDeliveryIds(c, current)
        if (fresh.isEmpty()) return
        notify(c, fresh)
    }

    private fun notify(c: Context, lines: List<String>) {
        try {
            val nm = c.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            if (Build.VERSION.SDK_INT >= 26) {
                nm.createNotificationChannel(
                    NotificationChannel(
                        CHANNEL,
                        c.getString(R.string.notif_deliveries_channel),
                        NotificationManager.IMPORTANCE_HIGH
                    )
                )
            }
            val tap = PendingIntent.getActivity(
                c, 1, Intent(c, MainActivity::class.java),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
            val title = if (lines.size == 1) c.getString(R.string.notif_new_delivery_one)
                        else c.getString(R.string.notif_new_delivery_many, lines.size)
            val notif = NotificationCompat.Builder(c, CHANNEL)
                .setSmallIcon(R.drawable.ic_rider)
                .setContentTitle(title)
                .setContentText(lines.joinToString("، "))
                .setStyle(NotificationCompat.BigTextStyle().bigText(lines.joinToString("\n")))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setAutoCancel(true)
                .setContentIntent(tap)
                .setDefaults(NotificationCompat.DEFAULT_ALL)
                .build()
            nm.notify(NOTIF_ID, notif)
        } catch (e: SecurityException) {
            // POST_NOTIFICATIONS not granted (Android 13+) — in-app list still updates.
        } catch (e: Exception) {
            // Notification failure must never crash duty/tracking flows.
        }
    }
}
