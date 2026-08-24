package pk.taxnest.callerid

/**
 * Notification-access builds ka faisla-saaz (plus + play).
 *
 * Sawal sirf teen hain aur teenon ka jawab YAHIN hai, taake code aur hamare
 * elaan (disclosure screen, privacy policy, Play ka Data safety form) hamesha
 * ek jaisa rahe:
 *
 *   1. Yeh notification uthani bhi hai ya nahi?  → [classify]
 *   2. Yeh incoming ring hai ya koi doosri halat? → [isNonIncoming]
 *   3. Number aur naam kya hai?                   → [extractNumber] / [extractName]
 *
 * ZAROORI USOOL (Task 1346, code review): consent ke baghair kuch nahi (dekhein
 * [gateOpen]), aur consent ke baad bhi hum SIRF do jagah se aayi incoming-call
 * notification parhte hain —
 *   • phone ki apni calling app (default dialer, system telephony, ya koi bhi
 *     app jo phone call kar sakti hai), aur
 *   • WhatsApp / WhatsApp Business.
 * Baqi har app ki notification — chahe uska category CATEGORY_CALL hi kyun na
 * ho (Messenger, Telegram, Viber, Skype, Zoom, Botim, IMO waghera) — chhoot
 * jati hai: na parhi jati hai, na bheji jati hai. Pehle sirf category dekhi
 * jati thi, jis se kisi bhi VoIP app ka caller naam/number "sim" ban kar chala
 * jata tha — yeh hamare apne disclosure se ziyada tha.
 *
 * Yeh file jaan-boojh kar bilkul plain JVM hai (koi android import nahi) taake
 * `src/notifTest` ke unit tests bina emulator ke chalein.
 */
object CallSourceRules {

    /** `android.app.Notification.CATEGORY_CALL` ki value. */
    const val CATEGORY_CALL = "call"

    const val SOURCE_WHATSAPP = "whatsapp"
    const val SOURCE_SIM = "sim"

    /** WhatsApp aur WhatsApp Business — sirf yeh do. */
    val WHATSAPP_PKGS = setOf("com.whatsapp", "com.whatsapp.w4b")

    /**
     * AOSP + baray OEMs ki telephony/dialer packages — **naam ba naam**, koi
     * pattern ya "jis ka aakhir .phone ho" jaisi khuli shart nahi (aisi shart
     * se koi bhi app apna package name badal kar andar aa sakti thi).
     *
     * Asal fehrist runtime par banti hai (default dialer + ACTION_DIAL handle
     * karne wali apps); yeh list us ke saath sirf un phones ke liye hai jo
     * incoming-call notification telecom/InCallUI se post karte hain aur woh
     * component ACTION_DIAL resolve nahi karta.
     */
    val KNOWN_DIALER_PKGS = setOf(
        "com.android.server.telecom",
        "com.android.dialer",
        "com.google.android.dialer",
        "com.android.incallui",
        "com.android.phone",
        "com.android.contacts",
        "com.samsung.android.dialer",
        "com.samsung.android.incallui",
        "com.samsung.android.contacts",
        "com.samsung.android.app.telephonyui",
        "com.miui.incallui",
        "com.transsion.dialer",
        "com.transsion.incallui",
        "com.oppo.dialer",
        "com.coloros.dialer",
        "com.vivo.dialer",
        "com.hihonor.contacts",
        "com.huawei.contacts",
    )

    /**
     * Incoming ring ke ilawa halaton ke lafz — inn mein se koi milte hi
     * notification chhod di jati hai.
     */
    val SKIP_WORDS = listOf(
        // English (dialers + WhatsApp)
        "ongoing", "outgoing", "dialing", "dialling", "calling",
        "ended", "missed", "on hold", "silenced", "declined",
        // Urdu-locale phones
        "جاری", "جا رہی", "ختم", "چھوٹی ہوئی", "مسڈ", "مسترد",
    )

    private val NUMBER_RE = Regex("[+0-9][0-9 \\-()]{8,}")

    /**
     * Service bilkul shuru mein yeh poochhti hai. Notification ka koi hissa
     * (title, text, extras) is ke `true` hone se pehle chhua tak nahi jata.
     *
     * @param hasToken            shop ka POS account is phone par signed-in hai
     * @param disclosureAccepted  user ne prominent disclosure par "agree" kiya
     *
     * Consent ka alag check is liye zaroori hai ke Android ki notification
     * access seedha Settings se bhi ON ho sakti hai (aur app ka data clear
     * hone par yeh flag mit jata hai) — us soorat mein hamare paas consent
     * hai hi nahi, to kuch parhna bhi nahi.
     */
    fun gateOpen(hasToken: Boolean, disclosureAccepted: Boolean): Boolean =
        hasToken && disclosureAccepted

    /**
     * @param pkg        notification kis app ki hai
     * @param ownPkg     hamara apna package (apni notification kabhi na uthayen)
     * @param category   `Notification.category`
     * @param dialerPkgs runtime par mili calling apps (default dialer +
     *                   ACTION_DIAL resolvers) — [CallListenerService] deti hai
     * @return "whatsapp" / "sim", ya null jab notification hamare elaan-shuda
     *         daire se bahar ho (tab kuch parha ya bheja nahi jata)
     */
    fun classify(
        pkg: String?,
        ownPkg: String?,
        category: String?,
        dialerPkgs: Set<String>,
    ): String? {
        if (pkg.isNullOrBlank()) return null
        if (!ownPkg.isNullOrBlank() && pkg == ownPkg) return null
        if (category != CATEGORY_CALL) return null
        if (pkg in WHATSAPP_PKGS) return SOURCE_WHATSAPP
        if (pkg in KNOWN_DIALER_PKGS) return SOURCE_SIM
        if (pkg in dialerPkgs) return SOURCE_SIM
        return null
    }

    /** Ongoing / outgoing / missed / ended waghera — ring nahi. */
    fun isNonIncoming(title: String, text: String): Boolean {
        val hay = (title + " " + text).lowercase()
        return SKIP_WORDS.any { hay.contains(it) }
    }

    // ── Aane wali call hai ya ja rahi? ──────────────────────────────────────

    /** `Notification.EXTRA_CALL_TYPE` ki values (CallStyle, Android 12+). */
    const val CALL_TYPE_INCOMING = 1
    const val CALL_TYPE_ONGOING = 2
    const val CALL_TYPE_SCREENING = 3

    /**
     * Yeh notification BAJTI hui (incoming) call ki hai?
     *
     * Lafzon ki fehrist akeli kaafi nahi thi: jab bahar jane wali call mil
     * jati hai to dialer notification par sirf naam aur timer reh jata hai
     * ("Bilal Traders 00:14") — na "outgoing", na "dialing" — aur woh bilkul
     * aane wali call jaisi lagti hai. Isi liye hamari apni milai hui call
     * counter par popup khol deti thi.
     *
     * Tarteeb jaan boojh kar yeh hai:
     *   1. Android ka apna elaan (CallStyle ka callType) — is se ziyada
     *      mustanad kuch nahi, magar sirf Android 12+ par milta hai.
     *   2. Lafz jo saaf batate hain ke ring nahi.
     *   3. Shak ki soorat mein HAAN. Ghalat "haan" se cashier ko ek fazool
     *      popup milta hai; ghalat "nahi" se dukaan ka grahak hi gum ho jata
     *      hai — aur woh ziyada mehnga nuqsan hai.
     *
     * Purane phone par jahan callType nahi milta, bahar jane wali call ka
     * asal pehra telephony par hai (dekhein RingCoordinator: bina RINGING ke
     * OFFHOOK = hum ne khud milai hai).
     *
     * @param callType `EXTRA_CALL_TYPE`, ya null jab notification CallStyle ki
     *                 na ho (purana Android / OEM ka apna template)
     */
    fun isIncomingRing(title: String, text: String, callType: Int?): Boolean {
        if (callType != null && callType > 0) return callType == CALL_TYPE_INCOMING
        return !isNonIncoming(title, text)
    }

    /** Number pehle title se, phir text se — jo bhi pehle mil jaye. */
    fun extractNumber(title: String, text: String): String? =
        NUMBER_RE.find(title)?.value ?: NUMBER_RE.find(text)?.value

    /** Naam sirf tab jab title khud number na ho (saved-contact wala case). */
    fun extractName(title: String): String? =
        title.takeIf { it.isNotBlank() && NUMBER_RE.find(it)?.value != it }
}
