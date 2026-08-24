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

            when (intent.getStringExtra(TelephonyManager.EXTRA_STATE)) {
                TelephonyManager.EXTRA_STATE_OFFHOOK -> {
                    // Bina baje call mil gai = number HUM ne milaya hai. Nishan
                    // laga do: dialer ki notification jaari call par sirf naam
                    // aur timer dikhati hai ("Bilal Traders 00:14") jis mein
                    // "outgoing/dialing" jaisa koi lafz nahi hota — aur woh
                    // bilkul aane wali call jaisi lagti hai. Nishan ke baghair
                    // counter par hamari apni milai hui call ka popup khulta hai.
                    if (!RingCoordinator.ringingFresh(context)) RingCoordinator.markOutgoingCall(context)
                    return
                }
                TelephonyManager.EXTRA_STATE_IDLE -> {
                    RingCoordinator.clearCallState(context)
                    return
                }
                TelephonyManager.EXTRA_STATE_RINGING -> Unit  // neeche
                else -> return
            }

            // Number mile ya na mile, "phone baja tha" darj hona zaroori hai —
            // warna agla OFFHOOK bahar jane wali call samjha jayega.
            RingCoordinator.markRinging(context)

            val number = intent.getStringExtra(TelephonyManager.EXTRA_INCOMING_NUMBER)?.trim()
            if (number.isNullOrBlank()) return          // number chhupa hua / permission nahi
            if (Prefs.token(context) == null) return    // sign-in ke baghair kuch nahi

            // Plus build mein yehi ring dialer ki notification se bhi dikhti hai
            // (jahan sirf naam hota hai). Nishan laga do taake wo copy chhoR di
            // jaye — warna counter par do popup aate, ek "No phone" wala.
            // Nishan POST se PEHLE, kyunki notification chand hi lamhon mein aa
            // jati hai aur network yahan slow ho sakta hai. Clean build ke liye
            // yeh bay-zarar hai (wahan koi parhne wala hi nahi).
            RingCoordinator.markTelephonyRing(context)

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
