package pk.taxnest.callerid

import android.content.Context
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * Ring → server. SHARED by BOTH builds (Task 1345) so the POS sale-screen popup
 * behaves exactly the same no matter which APK the shop installed:
 *   - same /ring payload  {phone, name, source, at}
 *   - same 60-second per-caller dedupe
 *   - same token/401 handling (token cleared → login screen on next app open)
 *
 * Builds:
 *   sim  ("clean")  → PhoneStateReceiver, Android telephony, SIM calls only.
 *   plus            → CallListenerService, dialer + WhatsApp notifications.
 *
 * Dedupe lives in SharedPreferences, NOT a static map: the sim build's detector
 * is a manifest BroadcastReceiver and Android may run each ring in a FRESH
 * process — an in-memory map would forget the previous ring and double-post.
 */
object RingReporter {

    /** Same caller inside this window = one ring only. */
    private const val WINDOW_MS = 60_000L

    /** Dedupe entries older than this are dropped so the pref never grows. */
    private const val PRUNE_MS = 300_000L

    /** Blocking — call from a background thread (or inside goAsync()). */
    fun report(context: Context, rawPhone: String?, rawName: String?, source: String) {
        val ctx = context.applicationContext
        val token = Prefs.token(ctx) ?: return

        val phone = rawPhone?.replace(Regex("[^+0-9]"), "")?.takeIf { it.length >= 9 }
        val name = rawName?.trim()?.takeIf { it.isNotEmpty() }
        if (phone == null && name == null) return

        val now = System.currentTimeMillis()
        if (!claim(ctx, (phone ?: "") + "|" + (name ?: ""), now)) return

        val payload = JSONObject()
            .put("phone", phone ?: JSONObject.NULL)
            .put("name", name ?: JSONObject.NULL)
            .put("source", source)
            .put("at", now)

        val (code, _) = ApiClient.post("/ring", payload, token)
        if (code == 401) {
            // Token rotate ho gaya (device revoke / dusre phone se login) —
            // yahan clear, agli app-open par login screen.
            Prefs.setToken(ctx, null)
        } else if (code in 200..299) {
            Prefs.setLastSentAt(ctx, now)
        }
    }

    /** Fire-and-forget wrapper for callers that are already alive (the listener service). */
    fun reportAsync(context: Context, rawPhone: String?, rawName: String?, source: String) {
        thread(name = "caller-ring-post") {
            try {
                report(context, rawPhone, rawName, source)
            } catch (_: Exception) {
                // Detector kabhi crash na kare.
            }
        }
    }

    /** true = this caller may be sent now (and the window is claimed). */
    private fun claim(ctx: Context, key: String, now: Long): Boolean = synchronized(this) {
        val stored = try { JSONObject(Prefs.ringDedupe(ctx)) } catch (_: Exception) { JSONObject() }
        if (now - stored.optLong(key, 0L) < WINDOW_MS) return false

        val fresh = JSONObject()
        val keys = stored.keys()
        while (keys.hasNext()) {
            val k = keys.next()
            val t = stored.optLong(k, 0L)
            if (now - t < PRUNE_MS) fresh.put(k, t)
        }
        fresh.put(key, now)
        Prefs.setRingDedupe(ctx, fresh.toString())
        return true
    }
}
