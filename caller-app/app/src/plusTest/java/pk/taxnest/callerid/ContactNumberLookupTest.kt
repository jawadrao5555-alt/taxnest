package pk.taxnest.callerid

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * ContactNumberLookup ka faisla — plain JUnit, koi emulator nahi:
 *   gradle testPlusReleaseUnitTest
 *
 * Sirf `plus` flavor mein chalte hain (play mein us class ka no-op version hai).
 * Yahan ka har test ek hi sawal ka jawab hai: kya WhatsApp ke naam se nikla
 * number POS par SAHI customer kholega? Shak ki soorat mein jawab null hona
 * chahiye — naam-wala popup ghalat khata kholne se behtar hai.
 */
class ContactNumberLookupTest {

    private fun rows(vararg pairs: Pair<String, String>) = pairs.toList()

    // ---- canonical(): likhne ka andaz farq na daale ----

    @Test fun `local aur international shakl ek hi hain`() {
        assertEquals("03001234567", ContactNumberLookup.canonical("0300-1234567"))
        assertEquals("03001234567", ContactNumberLookup.canonical("+92 300 1234567"))
        assertEquals("03001234567", ContactNumberLookup.canonical("0092-300-1234567"))
        assertEquals("03001234567", ContactNumberLookup.canonical("(0300) 123 4567"))
    }

    @Test fun `landline bhi ek hi shakl mein aata hai`() {
        assertEquals("04235678901", ContactNumberLookup.canonical("042-35678901"))
        assertEquals("04235678901", ContactNumberLookup.canonical("+92 42 35678901"))
    }

    @Test fun `bohat chhota number, number hi nahi`() {
        assertNull(ContactNumberLookup.canonical("1234"))
        assertNull(ContactNumberLookup.canonical(""))
        assertNull(ContactNumberLookup.canonical(null))
    }

    // ---- resolve(): kis soorat mein number bheja jaye ----

    @Test fun `ek contact, ek number`() {
        val got = ContactNumberLookup.resolve(rows("Zahid Irfan" to "0300-1234567"), "Zahid Irfan")
        assertEquals("03001234567", got)
    }

    @Test fun `wohi number do andaz mein likha ho to bhi bhej do`() {
        val got = ContactNumberLookup.resolve(
            rows("Zahid Irfan" to "0300-1234567", "Zahid Irfan" to "+923001234567"),
            "Zahid Irfan",
        )
        assertEquals("03001234567", got)
    }

    @Test fun `aakhri hindse mil jayen magar number alag ho to KUCH nahi`() {
        // Purani (ghalat) soorat mein sirf aakhri 9 hindse dekhe jate the —
        // yeh dono us paimane par "ek" the aur ghalat khata khul sakta tha.
        val got = ContactNumberLookup.resolve(
            rows("Zahid Irfan" to "0300-1234567", "Zahid Irfan" to "0301-1234567"),
            "Zahid Irfan",
        )
        assertNull(got)
    }

    @Test fun `ek hi naam ke do bandey to KUCH nahi`() {
        val got = ContactNumberLookup.resolve(
            rows("Bhai Jan" to "03001234567", "Bhai Jan" to "03219876543"),
            "Bhai Jan",
        )
        assertNull(got)
    }

    @Test fun `aadha naam match hone par us row ko chhoR do`() {
        // CONTENT_FILTER_URI "Zahid" par "Zahid Irfan" aur "Zahid Traders" dono
        // deta hai — poora naam na mile to woh row ginti mein nahi aati.
        val got = ContactNumberLookup.resolve(
            rows("Zahid Irfan" to "03001234567", "Zahid Traders" to "03219876543"),
            "Zahid Irfan",
        )
        assertEquals("03001234567", got)
    }

    @Test fun `chhota baRa harf aur khali jagah farq nahi daalti`() {
        val got = ContactNumberLookup.resolve(rows("  zahid irfan " to "03001234567"), "Zahid Irfan")
        assertEquals("03001234567", got)
    }

    @Test fun `naam mila hi nahi`() {
        assertNull(ContactNumberLookup.resolve(rows("Koi Aur" to "03001234567"), "Zahid Irfan"))
        assertNull(ContactNumberLookup.resolve(emptyList(), "Zahid Irfan"))
    }
}
