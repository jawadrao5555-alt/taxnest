package pk.taxnest.rider

import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import org.json.JSONArray

/**
 * Instant new-delivery push (v1.5.0, Task #1106).
 *
 * The server sends a DATA-ONLY message (no `notification` block) whose
 * `deliveries` payload is the rider's CURRENT open list in exactly the shape
 * of the /me `deliveries` array.  Routing it through DeliveryNotifier.process
 * gives push the SAME dedupe as the 15-min poll (seen-set replaced with the
 * current list) — so push + poll can never double-notify the same bill, in
 * either order.
 *
 * The 15-min DeliveryCheckWorker stays scheduled as fallback for phones where
 * push fails (no Play Services, battery-killed FCM, missing config build).
 */
class PushService : FirebaseMessagingService() {

    /** Firebase rotates tokens at will — keep the server copy current. */
    override fun onNewToken(token: String) {
        Fcm.upload(applicationContext, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val c = applicationContext
        // Logged out (or 401-evicted) — a raced push must not notify.
        if (Prefs.token(c) == null) return
        val data = message.data

        // v1.7.0 (Task #1359): silent "sync now" nudge. The server sends this
        // the moment the live map sees a rider go quiet, so recovery does not
        // wait for the 15-min worker. A high-priority data message is also one
        // of the few states in which Android still lets a backgrounded app
        // start a foreground service — so this is our best shot at reviving
        // tracking on a battery-saver phone.
        if (data["type"] == "sync_now") {
            DutyWatchdog.ensureRunning(c)
            SyncWorker.schedule(c) // self-heal if the periodic job was dropped
            SyncWorker.runNow(c)   // survives us being killed right after this
            return
        }

        if (data["type"] != "new_deliveries") return
        val rawDeliveries = data["deliveries"] ?: return
        val arr = try {
            JSONArray(rawDeliveries)
        } catch (e: Exception) {
            return
        }
        // Empty is meaningful: reassignment may leave the previous rider with
        // no cards, and must clear stale cache/outbox work immediately.
        DeliveryNotifier.process(c, arr)
    }
}
