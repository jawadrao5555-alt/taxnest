package pk.taxnest.callerid

import android.content.Context
import android.os.SystemClock

/**
 * Ek ring, do raste — kaun sa rasta ginti mein aaye (v1.5.0).
 *
 * Sirf `plus` build mein dono raste ek sath zinda hote hain:
 *   telephony (PhoneStateReceiver)  → number hamesha, naam kabhi nahi
 *   notification (CallListenerService) → dialer par sirf NAAM (jab caller phone
 *                                        mein save ho), WhatsApp bhi
 *
 * Agar dono ek hi SIM ring bhej dein to counter par do popup aate hain (ek "No
 * phone" wala) — aur RingReporter ka dedupe unhein alag samajhta hai kyunki us
 * ki key phone+naam hai.
 *
 * Is liye telephony jab number ke sath report karti hai to yahan nishan lagati
 * hai, aur notification wala rasta pehle yeh nishan dekhta hai. Nishan na mile
 * (number chhupa hua, ya OEM ne EXTRA_INCOMING_NUMBER khali diya) to
 * notification wali copy hi jati hai — kuch na jane se naam bhi behtar hai.
 *
 * Ye class `src/main` mein hai (sirf telephony/notif mein nahi) kyunki dono
 * source sets is tak pohanchte hain aur Play build ise sirf compile karti hai,
 * bulati kabhi nahi.
 */
object RingCoordinator {

    /**
     * Telephony ki report ke itni der baad tak aane wali dialer notification
     * usi call ki samjhi jati hai. OEM dialer kabhi kabhi apni notification
     * kuch second baad post karta hai (aur ring ke doran usay update bhi karta
     * rehta hai), is liye khula rakha gaya hai.
     */
    const val FRESH_MS = 15_000L

    /**
     * Notification wala rasta itni der telephony ka intezar karta hai. Is ke
     * baad number aane ki umeed nahi rehti — naam wali report bhej do.
     */
    const val WAIT_MS = 5_000L

    /** PhoneStateReceiver: "yeh ring maine number ke sath bhej di hai". */
    fun markTelephonyRing(ctx: Context) {
        try { Prefs.setTelephonyRingAt(ctx, SystemClock.elapsedRealtime()) } catch (_: Exception) {}
    }

    /** Abhi abhi telephony ne koi ring number ke sath bheji hai? */
    fun telephonyRingFresh(ctx: Context): Boolean = try {
        val at = Prefs.telephonyRingAt(ctx)
        val age = SystemClock.elapsedRealtime() - at
        at > 0L && age >= 0L && age <= FRESH_MS
    } catch (_: Exception) {
        false
    }
}
