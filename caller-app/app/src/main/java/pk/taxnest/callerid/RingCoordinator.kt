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

    /**
     * Phone kitni der tak bajta reh sakta hai. RINGING ke baad OFFHOOK ka
     * matlab hai "call uthai gai" — is se purana nishan bay-kaar hai.
     */
    const val RINGING_MEMORY_MS = 120_000L

    /**
     * Bahar jane wali call ka nishan zyada se zyada itni der zinda. Aam halat
     * mein IDLE par khud mit jata hai; yeh cap sirf us soorat ke liye hai jab
     * IDLE broadcast kabhi na pohanche (process maar diya gaya) — warna nishan
     * hamesha ke liye chipak jata.
     */
    const val OUTGOING_MAX_MS = 4 * 60 * 60 * 1000L

    /** PhoneStateReceiver: "yeh ring maine number ke sath bhej di hai". */
    fun markTelephonyRing(ctx: Context) {
        try { Prefs.setTelephonyRingAt(ctx, SystemClock.elapsedRealtime()) } catch (_: Exception) {}
    }

    /** Abhi abhi telephony ne koi ring number ke sath bheji hai? */
    fun telephonyRingFresh(ctx: Context): Boolean = fresh(Prefs.telephonyRingAt(ctx), FRESH_MS)

    // ── Aane wali bनाम bahar jane wali call ────────────────────────────────
    //
    // Dialer ki notification khud nahi batati ke call aa rahi hai ya ja rahi
    // hai. Android 12+ par CallStyle ka callType batata hai, magar purane
    // phone par sirf lafz hote hain — aur jab bahar wali call MIL jati hai to
    // notification par sirf naam aur timer reh jata hai ("Bilal Traders
    // 00:14"), jis mein "outgoing/dialing" jaisa koi lafz hota hi nahi. Us
    // waqt notification bilkul aane wali call jaisi lagti hai.
    //
    // Telephony is ka do-took jawab deti hai: bina RINGING ke OFFHOOK ka
    // matlab hai ke number HUM ne milaya hai.

    /** PhoneStateReceiver: phone bajna shuru hua (aane wali call). */
    fun markRinging(ctx: Context) {
        try { Prefs.setRingingAt(ctx, SystemClock.elapsedRealtime()) } catch (_: Exception) {}
    }

    /** PhoneStateReceiver: bina baje call mil gai — yani hum ne milai hai. */
    fun markOutgoingCall(ctx: Context) {
        try { Prefs.setOutgoingCallAt(ctx, SystemClock.elapsedRealtime()) } catch (_: Exception) {}
    }

    /** Call khatam (IDLE) — dono nishan saaf. */
    fun clearCallState(ctx: Context) {
        try {
            Prefs.setRingingAt(ctx, 0L)
            Prefs.setOutgoingCallAt(ctx, 0L)
        } catch (_: Exception) {}
    }

    /** Abhi abhi phone baja tha? (OFFHOOK ko "uthai gai call" samajhne ke liye) */
    fun ringingFresh(ctx: Context): Boolean = fresh(Prefs.ringingAt(ctx), RINGING_MEMORY_MS)

    /**
     * Is waqt hamari apni milai hui call chal rahi hai?
     *
     * Nishan sirf telephony lagati hai. Jis build/phone par phone-permission
     * na ho wahan yeh hamesha false rehta hai aur purana rasta jyon ka tyon
     * chalta hai — yani is pehre se koi aane wali call kabhi nahi rukti.
     */
    fun outgoingCallActive(ctx: Context): Boolean = fresh(Prefs.outgoingCallAt(ctx), OUTGOING_MAX_MS)

    /**
     * Nishan itni der ke andar ka hai? Phone restart hone par elapsedRealtime
     * sifar se shuru hoti hai, is liye manfi umar ko bhi "purana" mana jata hai.
     */
    private fun fresh(at: Long, windowMs: Long): Boolean = try {
        val age = SystemClock.elapsedRealtime() - at
        at > 0L && age >= 0L && age <= windowMs
    } catch (_: Exception) {
        false
    }
}
