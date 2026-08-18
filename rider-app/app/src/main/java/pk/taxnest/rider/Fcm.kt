package pk.taxnest.rider

import android.content.Context
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * FCM token plumbing (v1.5.0, Task #1106 — instant new-delivery push).
 *
 * EVERY call is wrapped so this whole feature degrades to a silent no-op
 * when Firebase is unavailable:
 *  - build compiled without google-services.json → FirebaseApp never
 *    initializes → getInstance() throws IllegalStateException (caught here);
 *  - phone without Play Services → token fetch just fails.
 * In both cases the 15-min DeliveryCheckWorker poll keeps notifications
 * working exactly as in v1.4.x.
 *
 * Uses reflection-free direct calls — the dependency is always on the
 * classpath (firebase-bom), only its INITIALIZATION is config-dependent.
 */
object Fcm {

    /** Fetch the current FCM token and upload it — call after a successful login. */
    fun register(c: Context) {
        try {
            com.google.firebase.messaging.FirebaseMessaging.getInstance().token
                .addOnSuccessListener { t -> if (!t.isNullOrBlank()) upload(c, t) }
            // No failure listener needed: nothing to do — poll fallback covers.
        } catch (e: Exception) {
            // Firebase not configured on this build — dormant by design.
        }
    }

    /**
     * POST the token to the server (also called by PushService.onNewToken when
     * Firebase rotates it).  Skipped while logged out: the server pairs the
     * FCM token with the app_token row, and login re-registers anyway.
     */
    fun upload(c: Context, fcmToken: String) {
        val token = Prefs.token(c) ?: return
        thread(name = "fcm-register") {
            // ApiClient never throws (returns -1 on network failure). A lost
            // registration self-heals: next login re-registers, and Firebase
            // re-delivers onNewToken on token rotation.
            ApiClient.post("/fcm-token", JSONObject().put("token", fcmToken), token)
        }
    }

    /**
     * Best-effort LOCAL token invalidation on logout / session expiry, so a
     * push can never land on a signed-out phone even if the server-side clear
     * raced.  Server clears its stored copy independently (logout endpoint +
     * login rotation), so failure here is harmless.
     */
    fun clear() {
        thread(name = "fcm-clear") {
            try {
                com.google.firebase.messaging.FirebaseMessaging.getInstance().deleteToken()
            } catch (e: Exception) {
                // Not configured / no Play Services — nothing registered anyway.
            }
        }
    }
}
