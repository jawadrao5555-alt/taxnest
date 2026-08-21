package pk.taxnest.callerid

import android.content.Context
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity

/**
 * Har screen ka base (Task 1382).
 *
 * Do kaam:
 *  1. `attachBaseContext` mein chuni hui zubaan ka Context lagata hai — is ke
 *     baghair screen phone ki system language mein khul jati hai.
 *  2. Compact teen-tarfa language picker wire karta hai (jis layout mein
 *     `@layout/view_lang_switch` include hai).
 *
 * NAI SCREEN BANATE WAQT: `AppCompatActivity` nahi, YEHI extend karein — warna
 * woh ek screen zubaan badalne ke baad bhi purani zubaan mein rehti hai.
 */
abstract class BaseActivity : AppCompatActivity() {

    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(Lang.wrap(newBase))
    }

    /** setContentView ke baad call karein. Layout mein picker na ho to no-op. */
    protected fun attachLangSwitch() {
        val current = Lang.current(this)
        bindLangPill(R.id.langEn, Lang.EN, current)
        bindLangPill(R.id.langRur, Lang.RUR, current)
        bindLangPill(R.id.langUr, Lang.UR, current)
    }

    private fun bindLangPill(viewId: Int, code: String, current: String) {
        val pill: TextView = findViewById(viewId) ?: return
        // Highlight `android:state_selected` se aati hai (drawable/lang_pill +
        // color/lang_pill_text), is liye abhi wali zubaan saaf nazar aati hai.
        pill.isSelected = code == current
        pill.setOnClickListener {
            if (code == Lang.current(this)) return@setOnClickListener
            Lang.set(this, code)
            // Pref pehle likh chuki hai, is liye recreate() ka naya
            // attachBaseContext seedha nai zubaan uthata hai — screen usi waqt
            // badal jati hai.
            recreate()
        }
    }
}
