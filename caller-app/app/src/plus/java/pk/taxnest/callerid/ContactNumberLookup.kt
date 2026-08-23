package pk.taxnest.callerid

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.ContactsContract
import androidx.core.content.ContextCompat

/**
 * Naam → number, phone ki apni contact list se. SIRF `plus` (website) build.
 *
 * KYUN: WhatsApp apni incoming-call notification mein, jab caller phone mein
 * save ho, number likhta hi NAHI — sirf contact ka naam aata hai. Us se counter
 * par "No phone" aata tha aur customer ka khata/history match nahi hoti thi.
 * Shops kehte hain unke ziyada tar customers unke phone mein save hote hain, is
 * liye naam ko yahin number mein badal kar bheja jata hai.
 *
 * USOOL (ghalat customer ka khata kholna, na kholne se buhat mehnga hai):
 *  • Sirf BILKUL wohi naam (chhoTa/baRa harf chhoR kar) — CONTENT_FILTER_URI
 *    aadhe naam par bhi match karta hai, is liye har row ka display name dobara
 *    jaancha jata hai.
 *  • Us naam ke SAB numbers ek hi number nikalne chahiyen — poora number, sirf
 *    aakhri chand hindse nahi. "+92 300 1234567" aur "0300-1234567" ek hi hain
 *    (dekhein [canonical]); 0300-1234567 aur 0301-1234567 nahi. Zara sa bhi
 *    farq = null, yani purana naam-wala rawaiya.
 *  • Permission na ho to khamoshi se null — feature band, app waise hi chalti
 *    hai. Contacts kabhi server par nahi jatin; sirf jis ne call ki hai us ka
 *    number jata hai.
 *
 * Play build mein isi naam ki no-op copy hai (src/play/java) — wahan
 * READ_CONTACTS declare hi nahi hoti, is liye lookup mumkin hi nahi. Dono
 * copies ka API ek jaisa rehna chahiye, warna notification builds mein se ek
 * compile nahi hogi. [resolve] aur [canonical] ke unit tests
 * src/plusTest mein hain.
 */
object ContactNumberLookup {

    /** Chhote/aadhe naam (misal "Ali") galat contact utha sakte hain. */
    private const val MIN_NAME_LEN = 3

    /** Itne hindson se kam = number hi nahi (extension, short code). */
    private const val MIN_DIGITS = 7

    /**
     * @return contact ka number (mahalli shakl: 0300…), ya null jab naam mila
     *         hi nahi / us naam ke ek se ziyada mukhtalif number nikle /
     *         permission nahi hai.
     */
    fun numberFor(ctx: Context, rawName: String?): String? {
        val name = rawName?.trim()?.takeIf { it.length >= MIN_NAME_LEN } ?: return null
        val granted = try {
            ContextCompat.checkSelfPermission(ctx, Manifest.permission.READ_CONTACTS) ==
                PackageManager.PERMISSION_GRANTED
        } catch (_: Exception) { false }
        if (!granted) return null

        return try { resolve(rows(ctx, name), name) } catch (_: Exception) { null }
    }

    /** Contact list se (display name, number) joRe — sirf yeh hissa Android ka mohtaj hai. */
    private fun rows(ctx: Context, name: String): List<Pair<String, String>> {
        val uri = Uri.withAppendedPath(
            ContactsContract.CommonDataKinds.Phone.CONTENT_FILTER_URI,
            Uri.encode(name),
        )
        val cols = arrayOf(
            ContactsContract.CommonDataKinds.Phone.NUMBER,
            ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME_PRIMARY,
        )

        val out = ArrayList<Pair<String, String>>()
        ctx.contentResolver.query(uri, cols, null, null, null)?.use { c ->
            while (c.moveToNext()) {
                out += Pair(c.getString(1).orEmpty(), c.getString(0).orEmpty())
            }
        }
        return out
    }

    /**
     * Faisla — poori tarah pure, is liye JVM test mein jaancha ja sakta hai.
     *
     * @param rows (display name, number) — jaise contact list ne diye.
     * @return ek hi number nikle to wohi ([canonical] shakl mein), warna null.
     */
    internal fun resolve(rows: List<Pair<String, String>>, name: String): String? {
        val wanted = name.trim()
        var chosen: String? = null
        for ((display, number) in rows) {
            if (!display.trim().equals(wanted, ignoreCase = true)) continue   // aadha match — chhoR do
            val canon = canonical(number) ?: continue
            if (chosen == null) {
                chosen = canon
            } else if (chosen != canon) {
                return null      // ek hi naam ke do alag number — koi faisla nahi
            }
        }
        return chosen
    }

    /**
     * Number ki ek hi mahalli shakl — taake likhne ka andaz farq na daale.
     *
     *   "+92 300 1234567" · "0092-300-1234567" · "0300 1234567"  → "03001234567"
     *   "+92 42 35678901"                                        → "04235678901"
     *
     * Poora number banta hai, aakhri chand hindse nahi: do alag numbers ke
     * aakhri hindse aksar mil jate hain, aur us par bharosa karke ghalat
     * customer ka khata khul sakta tha.
     */
    internal fun canonical(number: String?): String? {
        var d = number?.filter { it.isDigit() } ?: return null
        if (d.startsWith("00")) d = d.drop(2)                       // 0092… → 92…
        if (d.startsWith("92") && d.length in 11..12) d = "0" + d.substring(2)
        return d.takeIf { it.length >= MIN_DIGITS }
    }
}
