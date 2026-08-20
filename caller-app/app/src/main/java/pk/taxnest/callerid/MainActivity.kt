package pk.taxnest.callerid

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
 * Status + seedhi on-boarding. Build-agnostic (Task 1345): jo permission is
 * build ko chahiye wohi maangta hai —
 *   sim ("clean") → Detector = phone + call log runtime permissions
 *   plus          → Detector = notification access
 * aur upar saaf likha hota hai ke yeh build kaunsi calls pakadti hai
 * (build_badge / build_badge_roman har flavor ki apni strings.xml se).
 *
 *  1) Apni build wali permission dein (is ke baghair kuch nahi)
 *  2) Battery exemption (taake Android app ko na maare)
 *  3) Test ring bhejein — sale screen par popup aana chahiye
 */
class MainActivity : AppCompatActivity() {

    private lateinit var welcomeText: TextView
    private lateinit var buildBadge: TextView
    private lateinit var buildBadgeRoman: TextView
    private lateinit var statusText: TextView
    private lateinit var permRow: TextView
    private lateinit var batteryRow: TextView
    private lateinit var lastSentRow: TextView
    private lateinit var permBtn: Button
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
        buildBadge = findViewById(R.id.buildBadge)
        buildBadgeRoman = findViewById(R.id.buildBadgeRoman)
        statusText = findViewById(R.id.statusText)
        permRow = findViewById(R.id.permRow)
        batteryRow = findViewById(R.id.batteryRow)
        lastSentRow = findViewById(R.id.lastSentRow)
        permBtn = findViewById(R.id.permBtn)
        batteryBtn = findViewById(R.id.batteryBtn)
        testBtn = findViewById(R.id.testBtn)
        updateRow = findViewById(R.id.updateRow)

        buildBadge.text = getString(R.string.build_badge)
        buildBadgeRoman.text = getString(R.string.build_badge_roman)

        permBtn.setOnClickListener { Detector.request(this) }

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

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == Detector.REQUEST_CODE) renderState()
    }

    private fun logoutLocal() {
        Prefs.setToken(this, null)
        startActivity(Intent(this, LoginActivity::class.java))
        finish()
    }

    private fun batteryExempt(): Boolean = try {
        (getSystemService(POWER_SERVICE) as PowerManager).isIgnoringBatteryOptimizations(packageName)
    } catch (_: Exception) { false }

    private fun renderState() {
        welcomeText.text = getString(R.string.welcome_fmt, Prefs.userName(this), Prefs.companyName(this))

        val permOn = Detector.granted(this)
        permRow.text = if (permOn) getString(R.string.perm_on) else getString(R.string.perm_off)
        permBtn.visibility = if (permOn) View.GONE else View.VISIBLE

        val battOn = batteryExempt()
        batteryRow.text = if (battOn) getString(R.string.battery_on) else getString(R.string.battery_off)
        batteryBtn.visibility = if (battOn) View.GONE else View.VISIBLE

        val featureOn = Prefs.featureEnabled(this)
        statusText.text = when {
            !permOn -> getString(R.string.status_need_permission)
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

    /**
     * Update check — HAR BUILD apna. `?build=sim|plus` bhejna zaroori hai warna
     * plus wale phone ko clean build ka APK mil jata aur WhatsApp detection
     * chupke se khatam ho jati. Comparison semver-strict hai: server par purana
     * version ho (ya beta phone aage ho) to jhoota banner nahi aata.
     */
    private fun checkUpdate() {
        thread {
            val (code, body) = ApiClient.get("/version?build=" + BuildConfig.BUILD_KIND, Prefs.token(this))
            if (code !in 200..299 || body == null) return@thread
            val latest = body.optString("latest")
            val url = body.optString("apk_url")
            if (url.isBlank() || !UpdateCheck.isNewer(latest, BuildConfig.VERSION_NAME)) return@thread
            runOnUiThread {
                updateRow.visibility = View.VISIBLE
                findViewById<Button>(R.id.updateBtn).setOnClickListener {
                    UpdateCheck.startDownload(this, url)
                }
            }
        }
    }
}
