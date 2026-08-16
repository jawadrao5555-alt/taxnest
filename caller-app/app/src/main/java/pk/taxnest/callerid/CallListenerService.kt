package pk.taxnest.callerid

import android.app.Notification
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * Dil of the app: Android hamein har notification deta hai; hum SIRF incoming
 * call wali (CATEGORY_CALL) uthate hain — normal dialer ki ring AUR WhatsApp /
 * WhatsApp Business ki call — number/naam nikaal kar server ko POST karte hain.
 *
 * Event-driven hai: koi polling, koi foreground service, koi background loop
 * nahi — battery par asar na-hone-ke-barabar.
 *
 * WhatsApp note: unsaved number ki call par title mein number hota hai;
 * saved contact par sirf naam milta hai — dono bhej dete hain, match server
 * ki zimmedari hai (naam-se-match popup par alag se batata hai).
 */
class CallListenerService : NotificationListenerService() {

    companion object {
        private val WHATSAPP_PKGS = setOf("com.whatsapp", "com.whatsapp.w4b")
        // Non-incoming states — inn lafzon wali notification chhor do.
        // (WhatsApp/dialers English UI: "Ongoing call", "Outgoing call",
        // "Calling…", "Call ended", "Missed call", video variants covered
        // by the same words.)
        private val SKIP_WORDS = listOf(
            "ongoing", "outgoing", "dialing", "dialling", "calling",
            "ended", "missed", "on hold", "silenced",
            // Urdu-locale phones (dialer UI in Urdu)
            "جاری", "جا رہی", "ختم", "چھوٹی ہوئی",
        )
        private val NUMBER_RE = Regex("[+0-9][0-9 \\-()]{8,}")

        // In-memory dedupe — WhatsApp/dialer ring ke doran notification baar
        // baar update hoti hai; same caller 60s ke andar dobara na bheje.
        private val lastSent = HashMap<String, Long>()
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        try {
            handle(sbn)
        } catch (_: Exception) {
            // Listener kabhi crash na kare — warna Android access hi tor deta hai.
        }
    }

    private fun handle(sbn: StatusBarNotification) {
        val token = Prefs.token(this) ?: return
        val n = sbn.notification ?: return
        if (n.category != Notification.CATEGORY_CALL) return

        val pkg = sbn.packageName ?: ""
        // Apni hi (agar kabhi) notification skip
        if (pkg == packageName) return
        val source = if (pkg in WHATSAPP_PKGS) "whatsapp" else "sim"

        val extras = n.extras
        val title = (extras.getCharSequence(Notification.EXTRA_TITLE) ?: "").toString().trim()
        val text = (extras.getCharSequence(Notification.EXTRA_TEXT) ?: "").toString().trim()
        val haystackLower = (title + " " + text).lowercase()
        if (SKIP_WORDS.any { haystackLower.contains(it) }) return

        // Number pehle title se, phir text se — jo bhi mile.
        val rawNumber = NUMBER_RE.find(title)?.value ?: NUMBER_RE.find(text)?.value
        val phone = rawNumber?.replace(Regex("[^+0-9]"), "")?.takeIf { it.length >= 9 }
        // Naam: title jab woh khud number na ho (saved-contact case).
        val name = title.takeIf { it.isNotBlank() && NUMBER_RE.find(it)?.value != it }

        if (phone == null && name.isNullOrBlank()) return

        // 60s dedupe per caller
        val key = (phone ?: "") + "|" + (name ?: "")
        val now = System.currentTimeMillis()
        synchronized(lastSent) {
            val prev = lastSent[key] ?: 0L
            if (now - prev < 60_000) return
            lastSent[key] = now
            // Map ko chhota rakho
            if (lastSent.size > 50) {
                val cutoff = now - 300_000
                lastSent.entries.removeAll { it.value < cutoff }
            }
        }

        val payload = JSONObject()
            .put("phone", phone ?: JSONObject.NULL)
            .put("name", name ?: JSONObject.NULL)
            .put("source", source)
            .put("at", now)

        thread(name = "caller-ring-post") {
            val (code, _) = ApiClient.post("/ring", payload, token)
            if (code == 401) {
                // Token rotate ho gaya (kisi aur phone se login) — yahan clear,
                // agli app-open par login screen.
                Prefs.setToken(this, null)
            } else if (code in 200..299) {
                Prefs.setLastSentAt(this, now)
            }
        }
    }
}
