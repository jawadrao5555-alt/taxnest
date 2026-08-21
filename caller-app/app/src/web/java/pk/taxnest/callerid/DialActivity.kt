package pk.taxnest.callerid

import android.app.Activity
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.widget.Toast
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * Task 1381 — notification par tap ka trampoline (koi UI nahi).
 *
 * System dialer number ke saath khol deta hai (ACTION_DIAL). Call lagane ke
 * liye phone par ek aur tap lagta hai — yeh jaan boojh kar hai: auto-dial
 * task ke daayre se bahar hai aur CALL_PHONE permission is app mein kabhi
 * nahi aani chahiye.
 */
class DialActivity : Activity() {

    /**
     * Task 1382 ka usool "har nai screen `BaseActivity` extend kare" — magar
     * yeh screen `Theme.NoDisplay` par chalti hai (koi UI hi nahi), aur
     * `BaseActivity` = `AppCompatActivity` jo AppCompat theme ke baghair crash
     * kar deti hai. Is liye zubaan yahan seedhi lagti hai; natija wohi hai.
     */
    override fun attachBaseContext(newBase: Context) {
        super.attachBaseContext(Lang.wrap(newBase))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val id = intent?.getIntExtra(EXTRA_ID, 0) ?: 0
        val dial = intent?.getStringExtra(EXTRA_DIAL).orEmpty()
        val notifId = intent?.getIntExtra(EXTRA_NOTIF_ID, 0) ?: 0

        if (notifId != 0) {
            try {
                (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager).cancel(notifId)
            } catch (e: Exception) { /* best-effort */ }
        }

        var ok = false
        if (dial.isNotBlank()) {
            try {
                startActivity(
                    Intent(Intent.ACTION_DIAL, Uri.parse("tel:" + Uri.encode(dial)))
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                )
                ok = true
            } catch (e: Exception) {
                Toast.makeText(this, getString(R.string.dial_open_failed) + " " + dial, Toast.LENGTH_LONG).show()
            }
        }

        // POS ko batao: dialer khul gaya (ya nahi) — recent-calls ka record.
        val token = Prefs.token(this)
        if (id > 0 && token != null) {
            val status = if (ok) "dialed" else "failed"
            thread(isDaemon = true) {
                try {
                    val body = JSONObject().put("id", id).put("status", status)
                    if (!ok) body.put("error", "no_dialer")
                    ApiClient.post("/dial-result", body, token)
                } catch (e: Exception) { /* best-effort */ }
            }
        }

        finish()
        overridePendingTransition(0, 0)
    }

    companion object {
        const val EXTRA_ID = "dial_id"
        const val EXTRA_DIAL = "dial_number"
        const val EXTRA_NOTIF_ID = "dial_notif_id"
    }
}
