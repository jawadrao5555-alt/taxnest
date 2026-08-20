package pk.taxnest.callerid

import android.app.Notification
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification

/**
 * "plus" build ka dil (SIM + WhatsApp): Android hamein har notification deta
 * hai; hum SIRF incoming call wali (CATEGORY_CALL) uthate hain — normal dialer
 * ki ring AUR WhatsApp / WhatsApp Business ki call — number/naam nikaal kar
 * RingReporter ko de dete hain (payload + 60s dedupe + 401 handling wahin hai,
 * bilkul "clean" build jaisa).
 *
 * Event-driven hai: koi polling, koi foreground service, koi background loop
 * nahi — battery par asar na-hone-ke-barabar.
 *
 * PLAY PROTECT (Task 1345): is service ki BIND_NOTIFICATION_LISTENER_SERVICE
 * permission Google ki "enhanced fraud protection" ki blocked chaar mein se ek
 * hai — is liye YEH build sirf "plus" flavor mein hai aur website se install
 * karne ke liye shop ko Play Protect waqti tor par band karna parta hai. Default
 * download "clean" (sim) build hai jismein yeh file compile hi nahi hoti.
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
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        try {
            handle(sbn)
        } catch (_: Exception) {
            // Listener kabhi crash na kare — warna Android access hi tor deta hai.
        }
    }

    private fun handle(sbn: StatusBarNotification) {
        if (Prefs.token(this) == null) return
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
        // Naam: title jab woh khud number na ho (saved-contact case).
        val name = title.takeIf { it.isNotBlank() && NUMBER_RE.find(it)?.value != it }

        RingReporter.reportAsync(this, rawNumber, name, source)
    }
}
