package pk.taxnest.callerid

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * CallSourceRules ke unit tests (plus + play dono flavors mein chalte hain):
 *
 *   gradle -p caller-app testPlusReleaseUnitTest testPlayReleaseUnitTest
 *
 * Sab se aham cheez jo yeh tests pakadte hain: koi bhi doosri VoIP/chat app
 * CATEGORY_CALL wali notification bhej de to hum usay **na** uthayen. Pehle
 * sirf category dekhi jati thi, jis se Messenger/Telegram/Viber ki call ka
 * naam-number bhi "sim" ban kar chala jata tha — yeh hamare apne disclosure,
 * privacy policy aur Play ke Data safety form se ziyada tha.
 */
class CallSourceRulesTest {

    private val ownPkg = "pk.taxnest.callerid"

    /** Jo runtime par milti hain: default dialer + ACTION_DIAL wali apps. */
    private val runtimeDialers = setOf("com.google.android.dialer", "com.truecaller")

    private fun classify(pkg: String?, category: String? = CallSourceRules.CATEGORY_CALL) =
        CallSourceRules.classify(pkg, ownPkg, category, runtimeDialers)

    // ── qubool: WhatsApp ────────────────────────────────────────────────────

    @Test
    fun whatsapp_call_is_whatsapp() {
        assertEquals("whatsapp", classify("com.whatsapp"))
        assertEquals("whatsapp", classify("com.whatsapp.w4b"))
    }

    // ── qubool: phone ki apni calling app ───────────────────────────────────

    @Test
    fun system_telephony_packages_are_sim() {
        for (pkg in listOf(
            "com.android.server.telecom",
            "com.android.dialer",
            "com.android.incallui",
            "com.samsung.android.incallui",
            "com.transsion.dialer",
        )) {
            assertEquals("sim for $pkg", "sim", classify(pkg))
        }
    }

    @Test
    fun runtime_discovered_dialer_is_sim() {
        // Truecaller default dialer ho to uski ring bhi SIM ki ring hai.
        assertEquals("sim", classify("com.truecaller"))
    }

    @Test
    fun unknown_package_is_rejected_even_if_it_is_named_like_a_dialer() {
        // Naam se koi rioayat nahi: package ya to naam-ba-naam fehrist mein ho,
        // ya runtime par phone ki calling app nikle.
        assertNull(classify("com.someoem.dialer"))
        assertNull(classify("com.evil.phone"))
        assertNull(classify("com.random.contacts"))
        assertNull(classify("com.someoem.incallui"))
    }

    // ── rad: baqi har app (yehi review wala masla tha) ──────────────────────

    @Test
    fun other_voip_apps_are_rejected_even_with_call_category() {
        for (pkg in listOf(
            "org.telegram.messenger",
            "com.facebook.orca",
            "com.viber.voip",
            "com.skype.raider",
            "us.zoom.videomeetings",
            "im.thebot.messenger",
            "com.imo.android.imoim",
            "com.google.android.apps.tachyon",
            "com.microsoft.teams",
            "com.discord",
        )) {
            assertNull("rejected: $pkg", classify(pkg))
        }
    }

    @Test
    fun non_call_categories_are_rejected() {
        assertNull(classify("com.whatsapp", "msg"))
        assertNull(classify("com.android.dialer", "status"))
        assertNull(classify("com.android.dialer", null))
        assertNull(classify("com.whatsapp", "CALL")) // category case-sensitive hai
    }

    @Test
    fun own_and_blank_packages_are_rejected() {
        assertNull(classify(ownPkg))
        assertNull(classify(""))
        assertNull(classify(null))
    }

    @Test
    fun empty_runtime_list_does_not_break_known_packages() {
        assertEquals(
            "sim",
            CallSourceRules.classify("com.android.dialer", ownPkg, CallSourceRules.CATEGORY_CALL, emptySet()),
        )
        assertNull(
            CallSourceRules.classify("com.truecaller", ownPkg, CallSourceRules.CATEGORY_CALL, emptySet()),
        )
    }

    // ── consent gate (notification khulne se bhi pehle) ─────────────────────

    @Test
    fun gate_needs_both_sign_in_and_consent() {
        assertTrue(CallSourceRules.gateOpen(hasToken = true, disclosureAccepted = true))
        // User ne Android Settings se seedha notification access de di, lekin
        // app ki disclosure kabhi dekhi hi nahi → kuch nahi parha jata.
        assertFalse(CallSourceRules.gateOpen(hasToken = true, disclosureAccepted = false))
        // App ka data clear ho gaya (consent flag mit gaya, access ON hi rahi).
        assertFalse(CallSourceRules.gateOpen(hasToken = false, disclosureAccepted = false))
        assertFalse(CallSourceRules.gateOpen(hasToken = false, disclosureAccepted = true))
    }

    // ── ring hai ya koi doosri halat ────────────────────────────────────────

    @Test
    fun incoming_ring_is_not_skipped() {
        assertFalse(CallSourceRules.isNonIncoming("0300 1234567", "Incoming call"))
        assertFalse(CallSourceRules.isNonIncoming("Bilal Traders", "WhatsApp voice call"))
        assertFalse(CallSourceRules.isNonIncoming("آنے والی کال", "0300 1234567"))
    }

    @Test
    fun ongoing_outgoing_missed_are_skipped() {
        assertTrue(CallSourceRules.isNonIncoming("Ongoing call", "0300 1234567"))
        assertTrue(CallSourceRules.isNonIncoming("Outgoing call", ""))
        assertTrue(CallSourceRules.isNonIncoming("Missed call", "Bilal Traders"))
        assertTrue(CallSourceRules.isNonIncoming("Calling…", "0300 1234567"))
        assertTrue(CallSourceRules.isNonIncoming("Call ended", ""))
    }

    @Test
    fun urdu_locale_states_are_skipped() {
        assertTrue(CallSourceRules.isNonIncoming("کال جاری ہے", "0300 1234567"))
        assertTrue(CallSourceRules.isNonIncoming("چھوٹی ہوئی کال", "بلال ٹریڈرز"))
        assertTrue(CallSourceRules.isNonIncoming("کال ختم ہو گئی", ""))
    }

    // ── number aur naam ─────────────────────────────────────────────────────

    @Test
    fun number_comes_from_title_then_text() {
        assertEquals("0300 1234567", CallSourceRules.extractNumber("0300 1234567", "Incoming"))
        assertEquals("+92 300 1234567", CallSourceRules.extractNumber("Incoming", "+92 300 1234567"))
        assertNull(CallSourceRules.extractNumber("Bilal Traders", "WhatsApp call"))
    }

    @Test
    fun name_only_when_title_is_not_the_number() {
        assertEquals("Bilal Traders", CallSourceRules.extractName("Bilal Traders"))
        assertNull(CallSourceRules.extractName("0300 1234567"))
        assertNull(CallSourceRules.extractName(""))
    }
}
