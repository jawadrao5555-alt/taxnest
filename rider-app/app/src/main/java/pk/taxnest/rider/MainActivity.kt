package pk.taxnest.rider

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import android.widget.Button
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.concurrent.thread

class MainActivity : AppCompatActivity() {

    private val REQ_FINE = 11
    private val REQ_BG = 12
    private val REQ_NOTIF = 13

    private lateinit var dutyBtn: Button
    private lateinit var statusText: TextView
    private lateinit var pendingText: TextView
    private lateinit var welcomeText: TextView
    private lateinit var summaryText: TextView
    private lateinit var updateRow: View

    private val ui = Handler(Looper.getMainLooper())
    private val refreshLoop = object : Runnable {
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

        dutyBtn = findViewById(R.id.dutyBtn)
        statusText = findViewById(R.id.statusText)
        pendingText = findViewById(R.id.pendingText)
        welcomeText = findViewById(R.id.welcomeText)
        summaryText = findViewById(R.id.summaryText)
        updateRow = findViewById(R.id.updateRow)

        welcomeText.text = getString(R.string.welcome, Prefs.riderName(this))

        dutyBtn.setOnClickListener { if (Prefs.duty(this)) endDuty() else beginDutyChain() }

        findViewById<Button>(R.id.logoutBtn).setOnClickListener {
            thread { ApiClient.post("/logout", JSONObject(), Prefs.token(this)) }
            stopService(Intent(this, TrackingService::class.java))
            Prefs.clearSession(this)
            startActivity(Intent(this, LoginActivity::class.java)); finish()
        }

        findViewById<Button>(R.id.updateBtn).setOnClickListener {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiClient.DOWNLOAD_PAGE)))
        }
    }

    override fun onResume() {
        super.onResume()
        renderState()
        ui.removeCallbacks(refreshLoop)
        ui.postDelayed(refreshLoop, 5000)
        refreshMe()
        checkUpdate()
    }

    override fun onPause() {
        super.onPause()
        ui.removeCallbacks(refreshLoop)
    }

    // ── Duty ON: permission chain, then server, then service ──────────────

    private fun beginDutyChain() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this,
                arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION), REQ_FINE)
            return
        }
        if (Build.VERSION.SDK_INT >= 29 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            != PackageManager.PERMISSION_GRANTED) {
            AlertDialog.Builder(this)
                .setMessage(R.string.perm_background_needed)
                .setPositiveButton(R.string.open_settings) { _, _ ->
                    if (Build.VERSION.SDK_INT >= 30) {
                        startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                            Uri.fromParts("package", packageName, null)))
                    } else {
                        ActivityCompat.requestPermissions(this,
                            arrayOf(Manifest.permission.ACCESS_BACKGROUND_LOCATION), REQ_BG)
                    }
                }
                .setNegativeButton(R.string.skip) { _, _ -> afterBackgroundPerm() }
                .show()
            return
        }
        afterBackgroundPerm()
    }

    private fun afterBackgroundPerm() {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, arrayOf(Manifest.permission.POST_NOTIFICATIONS), REQ_NOTIF)
            return
        }
        askBatteryThenStart()
    }

    private fun askBatteryThenStart() {
        val pm = getSystemService(POWER_SERVICE) as PowerManager
        if (!pm.isIgnoringBatteryOptimizations(packageName)) {
            AlertDialog.Builder(this)
                .setMessage(R.string.perm_battery)
                .setPositiveButton(R.string.open_settings) { _, _ ->
                    try {
                        startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                            Uri.parse("package:$packageName")))
                    } catch (e: Exception) {
                        try { startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) } catch (e2: Exception) {}
                    }
                    startDutyOnServer()
                }
                .setNegativeButton(R.string.skip) { _, _ -> startDutyOnServer() }
                .show()
            return
        }
        startDutyOnServer()
    }

    private fun startDutyOnServer() {
        dutyBtn.isEnabled = false
        thread {
            val (code, body) = ApiClient.post("/duty", JSONObject().put("on", true), Prefs.token(this))
            runOnUiThread {
                dutyBtn.isEnabled = true
                when {
                    code in 200..299 -> {
                        Prefs.setDuty(this, true)
                        ContextCompat.startForegroundService(this, Intent(this, TrackingService::class.java))
                        renderState()
                    }
                    code == 401 -> sessionExpired()
                    code == 403 -> showMsg(body?.optString("message") ?: getString(R.string.plan_locked))
                    else -> showMsg(getString(R.string.network_error))
                }
            }
        }
    }

    private fun endDuty() {
        dutyBtn.isEnabled = false
        thread {
            ApiClient.post("/duty", JSONObject().put("on", false), Prefs.token(this))
            runOnUiThread {
                dutyBtn.isEnabled = true
                Prefs.setDuty(this, false)
                stopService(Intent(this, TrackingService::class.java))
                renderState()
            }
        }
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        when (requestCode) {
            REQ_FINE -> if (grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED)
                beginDutyChain() else showMsg(getString(R.string.perm_location_needed))
            REQ_BG -> afterBackgroundPerm()
            REQ_NOTIF -> askBatteryThenStart()
        }
    }

    // ── UI state ───────────────────────────────────────────────────────────

    private fun renderState() {
        val onDuty = Prefs.duty(this)
        dutyBtn.text = getString(if (onDuty) R.string.duty_off else R.string.duty_on)
        dutyBtn.setBackgroundColor(ContextCompat.getColor(this,
            if (onDuty) android.R.color.holo_red_dark else android.R.color.holo_green_dark))
        statusText.text = getString(if (onDuty) R.string.duty_running else R.string.duty_stopped)

        val pending = PointQueue.size(this)
        val last = Prefs.lastSync(this)
        val lastStr = if (last == 0L) getString(R.string.never)
            else SimpleDateFormat("hh:mm a", Locale.getDefault()).format(Date(last))
        pendingText.text = getString(R.string.points_pending, pending) + "  ·  " + getString(R.string.last_sync, lastStr)
    }

    private fun refreshMe() {
        thread {
            val (code, body) = ApiClient.get("/me", Prefs.token(this))
            if (code == 401) { runOnUiThread { sessionExpired() }; return@thread }
            if (code in 200..299 && body?.optBoolean("ok") == true) {
                val serverDuty = body.optBoolean("duty", false)
                val deliveries = body.optInt("open_deliveries", 0)
                val khata = body.optDouble("khata_owed", 0.0)
                runOnUiThread {
                    // Server is the boss for duty state.
                    if (Prefs.duty(this) != serverDuty) {
                        Prefs.setDuty(this, serverDuty)
                        if (!serverDuty) stopService(Intent(this, TrackingService::class.java))
                        else ContextCompat.startForegroundService(this, Intent(this, TrackingService::class.java))
                    }
                    summaryText.text = getString(R.string.open_deliveries, deliveries) + "\n" +
                        getString(R.string.khata_owed, String.format(Locale.US, "%,.0f", khata))
                    renderState()
                }
            }
        }
    }

    private fun checkUpdate() {
        thread {
            val (code, body) = ApiClient.get("/version")
            if (code in 200..299 && body != null) {
                val latest = body.optString("latest", "")
                if (latest.isNotBlank() && latest != BuildConfig.VERSION_NAME) {
                    runOnUiThread { updateRow.visibility = View.VISIBLE }
                }
            }
        }
    }

    private fun sessionExpired() {
        stopService(Intent(this, TrackingService::class.java))
        Prefs.clearSession(this)
        showMsg(getString(R.string.session_expired))
        startActivity(Intent(this, LoginActivity::class.java)); finish()
    }

    private fun showMsg(msg: String) {
        android.widget.Toast.makeText(this, msg, android.widget.Toast.LENGTH_LONG).show()
    }
}
