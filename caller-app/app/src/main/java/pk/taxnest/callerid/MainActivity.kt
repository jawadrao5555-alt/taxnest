package pk.taxnest.callerid

import android.content.ComponentName
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import android.widget.Button
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * Status + seedhi Urdu on-boarding:
 *  1) Notification access dein (NotificationListenerService ke bina kuch nahi)
 *  2) Battery exemption (taake Android service ko na maare)
 *  3) Test ring bhejein — sale screen par popup aana chahiye
 */
class MainActivity : AppCompatActivity() {

    private lateinit var welcomeText: TextView
    private lateinit var statusText: TextView
    private lateinit var notifAccessRow: TextView
    private lateinit var batteryRow: TextView
    private lateinit var lastSentRow: TextView
    private lateinit var notifBtn: Button
    private lateinit var batteryBtn: Button
    private lateinit var testBtn: Button
    private lateinit var updateRow: View

    private val ui = Handler(Looper.getMainLooper())
    private val stateLoop = object : Runnable {
        override fun run() {
            renderState()
            ui.postDelayed(this, 5000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Prefs.token(this) == null) {
            startActivity(Intent(this, LoginActivity::class.java)); finish(); return
        }
        setContentView(R.layout.activity_main)

        welcomeText = findViewById(R.id.welcomeText)
        statusText = findViewById(R.id.statusText)
        notifAccessRow = findViewById(R.id.notifAccessRow)
        batteryRow = findViewById(R.id.batteryRow)
        lastSentRow = findViewById(R.id.lastSentRow)
        notifBtn = findViewById(R.id.notifBtn)
        batteryBtn = findViewById(R.id.batteryBtn)
        testBtn = findViewById(R.id.testBtn)
        updateRow = findViewById(R.id.updateRow)

        notifBtn.setOnClickListener {
            try {
                startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))
                Toast.makeText(this, getString(R.string.notif_toast), Toast.LENGTH_LONG).show()
            } catch (_: Exception) {}
        }

        batteryBtn.setOnClickListener {
            try {
                startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                    Uri.parse("package:$packageName")))
            } catch (_: Exception) {
                try { startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) } catch (_: Exception) {}
            }
        }

        testBtn.setOnClickListener {
            testBtn.isEnabled = false
            thread {
                val payload = JSONObject()
                    .put("phone", JSONObject.NULL)
                    .put("name", "TaxNest Test")
                    .put("source", "sim")
                    .put("at", System.currentTimeMillis())
                val (code, body) = ApiClient.post("/ring", payload, Prefs.token(this))
                runOnUiThread {
                    testBtn.isEnabled = true
                    when {
                        code == 401 -> logoutLocal()
                        code in 200..299 && body?.optBoolean("accepted") == true ->
                            Toast.makeText(this, getString(R.string.test_sent), Toast.LENGTH_LONG).show()
                        code in 200..299 && body?.optString("reason") == "disabled" ->
                            Toast.makeText(this, getString(R.string.feature_off_toast), Toast.LENGTH_LONG).show()
                        else ->
                            Toast.makeText(this, getString(R.string.network_error), Toast.LENGTH_LONG).show()
                    }
                }
            }
        }

        findViewById<Button>(R.id.logoutBtn).setOnClickListener {
            thread {
                ApiClient.post("/logout", JSONObject(), Prefs.token(this))
                runOnUiThread { logoutLocal() }
            }
        }

        checkUpdate()
    }

    override fun onResume() {
        super.onResume()
        ui.post(stateLoop)
        refreshMe()
    }

    override fun onPause() {
        super.onPause()
        ui.removeCallbacks(stateLoop)
    }

    private fun logoutLocal() {
        Prefs.setToken(this, null)
        startActivity(Intent(this, LoginActivity::class.java))
        finish()
    }

    private fun notifAccessOn(): Boolean = try {
        val flat = Settings.Secure.getString(contentResolver, "enabled_notification_listeners") ?: ""
        flat.split(":").any { ComponentName.unflattenFromString(it)?.packageName == packageName }
    } catch (_: Exception) { false }

    private fun batteryExempt(): Boolean = try {
        (getSystemService(POWER_SERVICE) as PowerManager).isIgnoringBatteryOptimizations(packageName)
    } catch (_: Exception) { false }

    private fun renderState() {
        welcomeText.text = getString(R.string.welcome_fmt, Prefs.userName(this), Prefs.companyName(this))

        val notifOn = notifAccessOn()
        notifAccessRow.text = if (notifOn) getString(R.string.notif_on) else getString(R.string.notif_off)
        notifBtn.visibility = if (notifOn) View.GONE else View.VISIBLE

        val battOn = batteryExempt()
        batteryRow.text = if (battOn) getString(R.string.battery_on) else getString(R.string.battery_off)
        batteryBtn.visibility = if (battOn) View.GONE else View.VISIBLE

        val featureOn = Prefs.featureEnabled(this)
        statusText.text = when {
            !notifOn -> getString(R.string.status_need_permission)
            !featureOn -> getString(R.string.status_feature_off)
            else -> getString(R.string.status_ok)
        }

        val last = Prefs.lastSentAt(this)
        lastSentRow.visibility = if (last > 0) View.VISIBLE else View.GONE
        if (last > 0) {
            val mins = ((System.currentTimeMillis() - last) / 60000L).coerceAtLeast(0)
            lastSentRow.text = getString(R.string.last_sent_fmt, if (mins < 1) getString(R.string.just_now) else getString(R.string.mins_ago_fmt, mins))
        }
    }

    /** /me — feature toggle + company/user naam server se taza karo. */
    private fun refreshMe() {
        thread {
            val (code, body) = ApiClient.get("/me", Prefs.token(this))
            runOnUiThread {
                if (code == 401) { logoutLocal(); return@runOnUiThread }
                if (code in 200..299 && body?.optBoolean("ok") == true) {
                    Prefs.setFeatureEnabled(this, body.optBoolean("enabled", false))
                    body.optString("user").takeIf { it.isNotBlank() }?.let { Prefs.setUserName(this, it) }
                    body.optString("company").takeIf { it.isNotBlank() }?.let { Prefs.setCompanyName(this, it) }
                    renderState()
                }
            }
        }
    }

    private fun checkUpdate() {
        thread {
            val (code, body) = ApiClient.get("/version", Prefs.token(this))
            if (code !in 200..299 || body == null) return@thread
            val latest = body.optString("latest")
            val url = body.optString("apk_url")
            if (latest.isBlank() || url.isBlank() || latest == BuildConfig.VERSION_NAME) return@thread
            runOnUiThread {
                updateRow.visibility = View.VISIBLE
                findViewById<Button>(R.id.updateBtn).setOnClickListener {
                    UpdateCheck.startDownload(this, url)
                }
            }
        }
    }
}
