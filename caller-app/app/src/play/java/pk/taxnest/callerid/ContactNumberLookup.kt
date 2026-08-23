package pk.taxnest.callerid

import android.content.Context

/**
 * Play build ka no-op — bilkul `Updater` wala tareeqa (src/play/java).
 *
 * Website ki `plus` build naam ka number phone ki contact list se nikaalti hai
 * (src/plus/java/ContactNumberLookup.kt). Play build mein yeh feature hai hi
 * nahi: wahan READ_CONTACTS declare nahi hoti, aur Play ki User Data policy ke
 * liye contacts ka istemal alag se elaan karna parta hai jo is listing ne kabhi
 * nahi kiya. Shared `CallListenerService` dono builds mein ek hi call likhti
 * hai, is liye yahan wohi API null ke saath maujood hai.
 *
 * Is file mein kabhi asli lookup na likhein — [CallSourceRules] wali hi baat
 * hai: code aur elaan hamesha barabar rahen.
 */
object ContactNumberLookup {

    /** Play build: hamesha null — contacts kabhi parhi hi nahi jatin. */
    @Suppress("UNUSED_PARAMETER")
    fun numberFor(ctx: Context, rawName: String?): String? = null
}
