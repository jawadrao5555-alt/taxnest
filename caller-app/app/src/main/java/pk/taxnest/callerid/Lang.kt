package pk.taxnest.callerid

import android.content.Context
import android.content.res.Configuration
import java.util.Locale

/**
 * App ki apni zubaan (Task 1382) — English / Roman Urdu / Urdu.
 *
 * POS panel ke teen locales ke barabar (`app/Support/PosLocale.php`), magar
 * DEFAULT yahan **English** hai: owner ka faisla hai ke jab tak user khud
 * zubaan na chune, app English mein khule — chahe phone Urdu par ho.
 *
 * Is liye locale HAMESHA yahin se lagti hai; phone ki system language kabhi
 * nahi dekhi jati. Har screen `BaseActivity` se aati hai aur
 * `attachBaseContext` mein [wrap] chalta hai, so resources chuni hui zubaan se
 * hi uthti hain.
 *
 * Resource folders (aapt2 BCP-47 qualifier):
 *   values/               → en   (default — koi qualifier nahi)
 *   values-b+ur+Latn/     → rur  (Roman Urdu)
 *   values-ur/            → ur   (Urdu script)
 *
 * Roman Urdu Android ki koi standard zubaan nahi hai — is ka koi plain
 * qualifier mojood nahi. `ur-Latn` (Urdu likhi hui Latin script mein) ek asli
 * BCP-47 tag hai aur `values-b+ur+Latn` us se BILKUL match karta hai, is liye
 * phone English par ho ya Urdu par, config yahan se set hone ki wajah se hamesha
 * theek set uthta hai. Iska ek aur faida: ICU `ur-Latn` ko LTR maanta hai
 * (script Latn), so Roman Urdu screen ulti nahi hoti — sirf `ur` RTL hai.
 */
object Lang {

    const val EN = "en"
    const val RUR = "rur"
    const val UR = "ur"

    val ALL = listOf(EN, RUR, UR)

    /** Owner ka faisla: fresh install English mein khulta hai. */
    const val DEFAULT = EN

    fun normalize(value: String?): String = if (ALL.contains(value)) value!! else DEFAULT

    fun current(c: Context): String = normalize(Prefs.lang(c))

    fun set(c: Context, code: String) = Prefs.setLang(c, normalize(code))

    private fun locale(code: String): Locale = when (code) {
        UR -> Locale.forLanguageTag("ur")
        RUR -> Locale.forLanguageTag("ur-Latn")
        else -> Locale.forLanguageTag("en")
    }

    /**
     * Chuni hui zubaan wala Context. `BaseActivity.attachBaseContext` se chalta
     * hai; jo jagah Activity ke bahar hai (misal DownloadManager ka receiver,
     * jise sirf applicationContext milta hai) woh bhi isi se string uthaye,
     * warna wahan phone ki system language aa jati hai.
     */
    fun wrap(base: Context): Context {
        val loc = locale(current(base))
        val cfg = Configuration(base.resources.configuration)
        cfg.setLocale(loc)
        cfg.setLayoutDirection(loc)
        return base.createConfigurationContext(cfg)
    }
}
