package pk.taxnest.callerid

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.telephony.TelephonyManager
import kotlin.concurrent.thread

/**
 * "clean" (sim) build ka dil — Android ka apna telephony broadcast.
 *
 * PLAY PROTECT (Task 1345): Google ki "enhanced fraud protection" sirf chaar
 * permissions par sideload install block karti hai — RECEIVE_SMS, READ_SMS,
 * notification-listener aur accessibility. Yahan un mein se koi nahi:
 * READ_PHONE_STATE + READ_CALL_LOG blocked list mein nahi hain, is liye yeh APK
 * browser se install karne par bhi block nahi hoti.
 *
 * Sirf incoming (RINGING) call. Number Android 9+ par READ_CALL_LOG ke baghair
 * khali aata hai — dono permissions MainActivity par ek sath maangi jati hain.
 * WhatsApp calls yahan se NAHI milti (woh sirf notification se pata chalti hain)
 * — uske liye alag "plus" build hai.
 *
 * Manifest-registered receiver hai: app band ho ya phone abhi abhi restart hua
 * ho, Android khud jagata hai (PHONE_STATE implicit-broadcast pabandi se
 * mustasna hai). Network kaam goAsync() ke andar chalta hai taake process
 * POST mukammal hone se pehle na maara jaye.
 */
class PhoneStateReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        try {
            if (intent.action != TelephonyManager.ACTION_PHONE_STATE_CHANGED) return
            if (intent.getStringExtra(TelephonyManager.EXTRA_STATE) != TelephonyManager.EXTRA_STATE_RINGING) return

            val number = intent.getStringExtra(TelephonyManager.EXTRA_INCOMING_NUMBER)?.trim()
            if (number.isNullOrBlank()) return          // number chhupa hua / permission nahi
            if (Prefs.token(context) == null) return    // sign-in ke baghair kuch nahi

            val pending = goAsync()
            thread(name = "caller-ring-post") {
                try {
                    RingReporter.report(context, number, null, "sim")
                } catch (_: Exception) {
                    // Ring par app kabhi crash na kare.
                } finally {
                    try { pending.finish() } catch (_: Exception) {}
                }
            }
        } catch (_: Exception) {
            // Receiver kabhi crash na kare.
        }
    }
}
