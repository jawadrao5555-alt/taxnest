package pk.taxnest.callerid

import android.app.Notification
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import android.telecom.TelecomManager
import kotlin.concurrent.thread

/**
 * Notification builds (plus + play) ka dil: Android hamein har notification
 * deta hai, hum SIRF apne elaan-shuda daire wali incoming-call notification
 * uthate hain — phone ki apni calling app (default dialer / system telephony)
 * aur WhatsApp / WhatsApp Business — number aur naam nikaal kar RingReporter
 * ko de dete hain (payload + 60s dedupe + 401 handling wahin hai, bilkul
 * "clean" build jaisa).
 *
 * Kaunsi notification qubool hai, iska poora faisla [CallSourceRules] mein hai
 * (aur wahin unit tests bhi lagte hain). Yahan sirf woh hissa hai jise Android
 * chahiye: notification ka aana, aur phone par mojood calling apps ki fehrist.
 *
 * Event-driven hai: koi polling, koi foreground service, koi background loop
 * nahi — battery par asar na-hone-ke-barabar.
 *
 * PLAY PROTECT (Task 1345): is service ki BIND_NOTIFICATION_LISTENER_SERVICE
 * permission Google ki "enhanced fraud protection" ki blocked chaar mein se ek
 * hai — is liye website par default download "clean" (sim) build hai jismein
 * yeh file compile hi nahi hoti. Play Store se aane wali build par yeh block
 * hota hi nahi (Task 1346).
 *
 * WhatsApp note: unsaved number ki call par title mein number hota hai;
 * saved contact par sirf naam milta hai — dono bhej dete hain, match server
 * ki zimmedari hai (naam-se-match popup par alag se batata hai).
 */
class CallListenerService : NotificationListenerService() {

    // Phone ki calling apps — mehnga kaam hai, is liye cache. Nai app install
    // ho ya default dialer badle to yeh 10 minute mein khud taaza ho jata hai
    // (aur listener dobara connect hone par foran).
    private var dialerPkgs: Set<String> = emptySet()
    private var dialerPkgsAt = 0L

    /**
     * Sirf SIM ki notification ko chand second rokne ke liye (v1.5.0) — dekhein
     * [RingCoordinator]. Notification callback isi (main) thread par aata hai,
     * aur yeh service bandhi rehti hai, is liye postDelayed mehfooz hai;
     * network kaam waise bhi alag thread par jata hai.
     */
    private val handler = Handler(Looper.getMainLooper())

    override fun onListenerConnected() {
        dialerPkgsAt = 0L
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        try {
            handle(sbn)
        } catch (_: Exception) {
            // Listener kabhi crash na kare — warna Android access hi tor deta hai.
        }
    }

    private fun handle(sbn: StatusBarNotification) {
        // Consent-gate SAB se pehle: signed-in bhi hona chahiye aur disclosure
        // par "agree" bhi hona chahiye. Android ki notification access seedha
        // Settings se ON ki ja sakti hai (aur app-data clear hone par consent
        // flag mit jata hai) — us haalat mein hum notification khol kar dekhte
        // tak nahi. MainActivity bhi tab tak "band" hi dikhati hai, aur agla
        // "Ijazat dein" tap disclosure screen par le jata hai.
        if (!CallSourceRules.gateOpen(Prefs.token(this) != null, Prefs.notifDisclosureAccepted(this))) return
        val n = sbn.notification ?: return

        // Daire se bahar (koi bhi doosri app, ya call ke ilawa notification) →
        // yahin khatam: na extras khole jate hain, na kuch bheja jata hai.
        val source = CallSourceRules.classify(
            sbn.packageName, packageName, n.category, dialerPackages(),
        ) ?: return

        // Hamari apni milai hui call ka pehra. Telephony do-took batati hai ke
        // bina baje call mili thi (yani number HUM ne milaya) — us ke doran
        // dialer ki notification par sirf naam aur timer hota hai, jo bilkul
        // aane wali call jaisi lagti hai. Nishan sirf telephony lagati hai, is
        // liye jis phone par woh permission na ho wahan yeh kabhi nahi chalta.
        if (source == CallSourceRules.SOURCE_SIM && RingCoordinator.outgoingCallActive(this)) return

        val extras = n.extras
        val title = (extras.getCharSequence(Notification.EXTRA_TITLE) ?: "").toString().trim()
        val text = (extras.getCharSequence(Notification.EXTRA_TEXT) ?: "").toString().trim()
        // Android 12+ khud bata deta hai ke call aa rahi hai ya ja rahi
        // (CallStyle ka callType); us se pehle sirf lafzon ka andaza tha.
        val callType = try {
            extras.getInt("android.callType", 0).takeIf { it > 0 }
        } catch (_: Exception) {
            null
        }
        if (!CallSourceRules.isIncomingRing(title, text, callType)) return

        val name = CallSourceRules.extractName(title)
        val number = CallSourceRules.extractNumber(title, text)

        // SIM ki call ab (v1.5.0) plus build mein telephony se bhi aati hai,
        // jahan number hamesha milta hai — chahe caller phone mein save ho.
        // Dialer ki notification par sirf naam hota hai. Dono chal paren to ek
        // ring ke DO event jate (ek "No phone" wala) — RingReporter ka dedupe
        // unhein alag samajhta hai kyunki us ki key phone+naam hai.
        //
        // Is liye: telephony ki ring ka nishan taaza ho to yeh copy chhoR do.
        // Nishan na ho to foran chhoRna GHALAT hoga — number chhupa hua ho, ya
        // OEM ne EXTRA_INCOMING_NUMBER khali diya ho, to telephony kuch bhejti
        // hi nahi aur ring bilkul zaya ho jati. Is liye chand second intezar,
        // phir bhi nishan na aaye to naam wali report bhej do.
        //
        // Play build: yeh permissions declare hi nahi hotin (na PhoneStateReceiver
        // compile hoti hai), is liye telephonyDetectorLive() hamesha false aur
        // SIM pehle ki tarah seedha notification se jati hai. Plus build par
        // shop ne phone permission na di ho, tab bhi yehi purana rasta chalta hai.
        if (source == CallSourceRules.SOURCE_SIM && telephonyDetectorLive()) {
            if (RingCoordinator.telephonyRingFresh(this)) return
            val ctx = applicationContext
            handler.postDelayed({
                if (!RingCoordinator.telephonyRingFresh(ctx)) {
                    reportWithContacts(number, name, source)
                }
            }, RingCoordinator.WAIT_MS)
            return
        }

        reportWithContacts(number, name, source)
    }

    /**
     * Report bhejo — aur agar notification mein number tha hi nahi (WhatsApp par
     * saved contact ki call) to usi naam ka number phone ki contact list se
     * nikaal kar. Lookup background thread par hota hai: ContentResolver ka
     * query main thread par nahi chalna chahiye, aur notification callback main
     * thread par aata hai. Play build mein lookup no-op hai.
     */
    private fun reportWithContacts(number: String?, name: String?, source: String) {
        val ctx = applicationContext
        thread(name = "caller-ring-post") {
            try {
                RingReporter.report(ctx, number ?: ContactNumberLookup.numberFor(ctx, name), name, source)
            } catch (_: Exception) {
                // Listener kabhi crash na kare.
            }
        }
    }

    /**
     * Is build mein SIM ki ring telephony se aa rahi hai?
     *
     * Dono permissions website ki plus build hi declare karti hai (aur wohi
     * src/telephony compile karti hai). Permission na mile to detector chal hi
     * nahi sakta — us soorat mein notification wala purana rasta hi behtar hai.
     */
    private fun telephonyDetectorLive(): Boolean = try {
        checkSelfPermission(android.Manifest.permission.READ_PHONE_STATE) ==
            android.content.pm.PackageManager.PERMISSION_GRANTED &&
            checkSelfPermission(android.Manifest.permission.READ_CALL_LOG) ==
            android.content.pm.PackageManager.PERMISSION_GRANTED
    } catch (_: Exception) {
        false
    }

    /**
     * Phone par jo apps call kar sakti hain: default dialer + har woh app jo
     * ACTION_DIAL handle karti hai (OEM dialers, aur Truecaller jaisi koi app
     * agar user ne default dialer banayi ho). Manifest ka <queries> block isi
     * ke liye hai — Android 11+ par uske baghair yeh fehrist khali aati hai.
     */
    private fun dialerPackages(): Set<String> {
        val now = SystemClock.elapsedRealtime()
        if (dialerPkgs.isNotEmpty() && now - dialerPkgsAt < CACHE_MS) return dialerPkgs

        val found = HashSet<String>()
        try {
            val tm = getSystemService(Context.TELECOM_SERVICE) as? TelecomManager
            tm?.defaultDialerPackage?.let { if (it.isNotBlank()) found += it }
        } catch (_: Exception) {
        }
        try {
            val pm = packageManager
            for (intent in listOf(
                Intent(Intent.ACTION_DIAL),
                Intent(Intent.ACTION_DIAL, Uri.parse("tel:")),
            )) {
                for (ri in pm.queryIntentActivities(intent, 0)) {
                    ri.activityInfo?.packageName?.let { found += it }
                }
            }
        } catch (_: Exception) {
        }

        dialerPkgs = found
        dialerPkgsAt = now
        return found
    }

    private companion object {
        const val CACHE_MS = 10 * 60 * 1000L
    }
}
