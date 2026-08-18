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
        if (data["type"] != "new_deliveries") return
        val arr = try {
            JSONArray(data["deliveries"] ?: "[]")
        } catch (e: Exception) {
            JSONArray()
        }
        // Empty/garbled payload: do NOT process — process() would REPLACE the
        // seen-set with an empty set and the next poll would re-alert every
        // open bill. Server never sends an empty list.
        if (arr.length() == 0) return
        DeliveryNotifier.process(c, arr)
    }
}
