package pk.taxnest.rider

import android.content.Intent
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.view.LayoutInflater
import android.view.View
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlin.concurrent.thread

class MainActivity : AppCompatActivity() {

    private val REQ_FINE = 11
    private val REQ_BG = 12
    private val REQ_NOTIF = 13
    // App-open notification ask (v1.4.0) — result is a no-op, must NOT fall
    // into the duty-chain REQ_NOTIF branch (that one continues to battery ask).
    private val REQ_NOTIF_OPEN = 14

    private lateinit var dutyBtn: Button
    private lateinit var statusText: TextView
    private lateinit var pendingText: TextView
    private lateinit var welcomeText: TextView
    private lateinit var summaryText: TextView
    private lateinit var updateRow: View
    private lateinit var deliveriesContainer: LinearLayout
    private lateinit var emptyDeliveriesText: TextView

    private val ui = Handler(Looper.getMainLooper())

    // 5-second loop — local Prefs only (duty bool, pending queue, last sync).
    // Never touches the network.
    private val localStateLoop = object : Runnable {
        override fun run() {
            renderState()
            ui.postDelayed(this, 5000)
        }
    }

    // 30-second loop — polls /me to refresh duty status + deliveries list.
    // Kept separate from the 5s loop so we don't hammer the server.
    private val meRefreshLoop = object : Runnable {
        override fun run() {
            refreshMe()
            ui.postDelayed(this, 30_000)
        }
    }

    // Network connectivity callback — fires QueueDrain and duty-off reconcile
    // whenever connectivity is restored.
    private var connectivityManager: ConnectivityManager? = null
    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            // Reconcile pending duty-off first (idempotent), then drain queue.
            thread(name = "connectivity-restore") {
                reconcilePendingDutyOff()
                QueueDrain.drainAsync(this@MainActivity)
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Prefs.token(this) == null) {
            startActivity(Intent(this, LoginActivity::class.java)); finish(); return
        }
        setContentView(R.layout.activity_main)

        dutyBtn            = findViewById(R.id.dutyBtn)
        statusText         = findViewById(R.id.statusText)
        pendingText        = findViewById(R.id.pendingText)
        welcomeText        = findViewById(R.id.welcomeText)
        summaryText        = findViewById(R.id.summaryText)
        updateRow          = findViewById(R.id.updateRow)
        deliveriesContainer = findViewById(R.id.deliveriesContainer)
        emptyDeliveriesText = findViewById(R.id.emptyDeliveriesText)

        welcomeText.text = getString(R.string.welcome, Prefs.riderName(this))

        dutyBtn.setOnClickListener { if (Prefs.duty(this)) endDuty() else beginDutyChain() }

        findViewById<Button>(R.id.logoutBtn).setOnClickListener {
            thread { ApiClient.post("/logout", JSONObject(), Prefs.token(this)) }
            stopService(Intent(this, TrackingService::class.java))
            DeliveryCheckWorker.cancel(this)
            Prefs.clearSession(this)
            startActivity(Intent(this, LoginActivity::class.java)); finish()
        }

        findViewById<Button>(R.id.updateBtn).setOnClickListener {
            startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiClient.DOWNLOAD_PAGE)))
        }

        // v1.4.0: background delivery check — notification even when the app
        // is closed / duty off (Touseef case). KEEP policy = idempotent.
        DeliveryCheckWorker.schedule(this)

        // Ask for notification permission on open (Android 13+) so alerts work
        // even for riders who never start duty from this screen.
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, arrayOf(Manifest.permission.POST_NOTIFICATIONS), REQ_NOTIF_OPEN)
        }
    }

    override fun onResume() {
        super.onResume()
        renderState()
        // Start both loops fresh (cancel any stale callbacks first).
        ui.removeCallbacks(localStateLoop)
        ui.removeCallbacks(meRefreshLoop)
        ui.postDelayed(localStateLoop, 5000)
        // Immediate /me fetch on resume (also handles pending duty-off reconcile);
        // next auto-refresh 30 s later.
        refreshMe()
        ui.postDelayed(meRefreshLoop, 30_000)
        checkUpdate()

        // Drain any buffered offline points left from previous duty sessions.
        QueueDrain.drainAsync(this)

        // Register connectivity callback so drain fires immediately when
        // signal returns, not just on the next 30s /me poll.
        registerConnectivityCallback()
    }

    override fun onPause() {
        super.onPause()
        ui.removeCallbacks(localStateLoop)
        ui.removeCallbacks(meRefreshLoop)
        unregisterConnectivityCallback()
    }

    // ── Connectivity callback ──────────────────────────────────────────────

    private fun registerConnectivityCallback() {
        try {
            val cm = getSystemService(CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return
            connectivityManager = cm
            val request = NetworkRequest.Builder()
                .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                .build()
            cm.registerNetworkCallback(request, networkCallback)
        } catch (e: Exception) {
            // Silently ignore — connectivity callback is an enhancement, not critical.
        }
    }

    private fun unregisterConnectivityCallback() {
        try {
            connectivityManager?.unregisterNetworkCallback(networkCallback)
        } catch (e: Exception) {}
        connectivityManager = null
    }

    // ── Duty ON: permission chain → server → service ──────────────────────

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
        // Set the pending flag BEFORE the network call so it survives process
        // death if we're killed mid-flight while offline.
        Prefs.setPendingDutyOff(this, true)
        thread {
            val (code, _) = ApiClient.post("/duty", JSONObject().put("on", false), Prefs.token(this))
            runOnUiThread {
                dutyBtn.isEnabled = true
                // 200–299 or 409 (already off) both count as reconciled.
                when {
                    code in 200..299 || code == 409 -> Prefs.setPendingDutyOff(this, false)
                    code == 401 -> {
                        Prefs.setPendingDutyOff(this, false)
                        sessionExpired(); return@runOnUiThread
                    }
                    // -1 / other transient failure: leave flag set; reconcile on next contact.
                }
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
            REQ_NOTIF_OPEN -> { /* app-open ask — no chain to continue */ }
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
            // Reconcile any pending duty-off before reading /me so the server
            // state is consistent when we sync the duty flag below.
            reconcilePendingDutyOff()

            val (code, body) = ApiClient.get("/me", Prefs.token(this))
            if (code == 401) { runOnUiThread { sessionExpired() }; return@thread }
            if (code in 200..299 && body?.optBoolean("ok") == true) {
                val serverDuty = body.optBoolean("duty", false)
                val deliveries = body.optInt("open_deliveries", 0)
                val khata = body.optDouble("khata_owed", 0.0)
                val deliveriesArr = body.optJSONArray("deliveries") ?: JSONArray()
                // Nayi assigned delivery → phone par awaz ke saath ittila
                // (background thread — notifications are thread-safe).
                DeliveryNotifier.process(this, deliveriesArr)
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
                    updateDeliveriesList(deliveriesArr)
                }
            }
        }
    }

    /**
     * Idempotent duty-off reconcile.  Posts /duty {on:false} to the server if
     * the pending flag is set.  Safe to call when the server already has
     * duty=false — the server /duty endpoint treats on=false as idempotent
     * (409 = already off; also treated as success here).
     * Must be called from a background thread.
     */
    private fun reconcilePendingDutyOff() {
        if (!Prefs.pendingDutyOff(this)) return
        val token = Prefs.token(this) ?: return
        val (code, _) = ApiClient.post("/duty", JSONObject().put("on", false), token)
        when (code) {
            in 200..299, 409 -> Prefs.setPendingDutyOff(this, false) // reconciled (409 = already off)
            401 -> {
                Prefs.setPendingDutyOff(this, false) // token gone — nothing to reconcile
                Prefs.clearToken(this)
            }
            // -1 / transient: leave flag set, retry next time
        }
    }

    /**
     * Rebuilds the deliveries list from the JSONArray returned by /me.
     * Must be called on the main thread.
     */
    private fun updateDeliveriesList(arr: JSONArray) {
        deliveriesContainer.removeAllViews()

        if (arr.length() == 0) {
            emptyDeliveriesText.visibility = View.VISIBLE
            return
        }
        emptyDeliveriesText.visibility = View.GONE

        val inflater = LayoutInflater.from(this)
        for (i in 0 until arr.length()) {
            val item = arr.optJSONObject(i) ?: continue
            val row = inflater.inflate(R.layout.item_delivery, deliveriesContainer, false)

            row.findViewById<TextView>(R.id.invoiceNumber).text =
                item.optString("invoice_number").ifBlank { "#${item.optInt("id")}" }

            val amountVal = item.optDouble("amount", 0.0)
            row.findViewById<TextView>(R.id.amount).text =
                getString(R.string.amount_fmt, String.format(Locale.US, "%,.0f", amountVal))

            row.findViewById<TextView>(R.id.customerName).text =
                item.optString("customer_name").ifBlank { getString(R.string.unknown_customer) }

            val pm = item.optString("payment_method")
            row.findViewById<TextView>(R.id.paymentMethod).text = localizePaymentMethod(pm)

            // Phone — tap to dial
            val phone = item.optString("customer_phone")
            val phoneView = row.findViewById<TextView>(R.id.phone)
            if (phone.isNotBlank()) {
                phoneView.text = getString(R.string.phone_prefix, phone)
                phoneView.visibility = View.VISIBLE
                phoneView.setOnClickListener {
                    try { startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) }
                    catch (e: Exception) { showMsg(getString(R.string.no_dialer)) }
                }
            } else {
                phoneView.visibility = View.GONE
            }

            // Address — tap to open maps_url
            val mapsUrl = item.optString("maps_url")
            val address = item.optString("address")
            val addrView = row.findViewById<TextView>(R.id.address)
            if (address.isNotBlank()) {
                addrView.text = getString(R.string.address_prefix, address)
                addrView.visibility = View.VISIBLE
                if (mapsUrl.isNotBlank()) {
                    addrView.setOnClickListener {
                        try { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(mapsUrl))) }
                        catch (e: Exception) { showMsg(getString(R.string.no_maps)) }
                    }
                }
            } else {
                addrView.visibility = View.GONE
            }

            // Assigned X min ago
            val mins = item.optInt("assigned_mins", -1)
            val minsView = row.findViewById<TextView>(R.id.assignedMins)
            minsView.text = if (mins >= 0) getString(R.string.assigned_mins_ago, mins)
                            else getString(R.string.assigned_just_now)

            deliveriesContainer.addView(row)
        }
    }

    /** Maps server payment_method values to display labels. */
    private fun localizePaymentMethod(pm: String): String = when (pm.lowercase()) {
        "cash"       -> getString(R.string.pm_cash)
        "debit_card", "card", "credit_card" -> getString(R.string.pm_card)
        "online"     -> getString(R.string.pm_online)
        else         -> pm.ifBlank { getString(R.string.pm_cash) }
    }

    private fun checkUpdate() {
        thread {
            val (code, body) = ApiClient.get("/version")
            if (code in 200..299 && body != null) {
                val latest = body.optString("latest", "")
                // Only prompt when the server version is strictly NEWER — a beta
                // build ahead of the server must not see a bogus update banner.
                if (latest.isNotBlank() && isNewerVersion(latest, BuildConfig.VERSION_NAME)) {
                    runOnUiThread { updateRow.visibility = View.VISIBLE }
                }
            }
        }
    }

    /** Numeric dot-segment compare: true when [latest] > [current]. */
    private fun isNewerVersion(latest: String, current: String): Boolean {
        val l = latest.split(".")
        val c = current.split(".")
        for (i in 0 until maxOf(l.size, c.size)) {
            val li = l.getOrNull(i)?.trim()?.toIntOrNull() ?: 0
            val ci = c.getOrNull(i)?.trim()?.toIntOrNull() ?: 0
            if (li != ci) return li > ci
        }
        return false
    }

    private fun sessionExpired() {
        stopService(Intent(this, TrackingService::class.java))
        DeliveryCheckWorker.cancel(this)
        Prefs.clearSession(this)
        showMsg(getString(R.string.session_expired))
        startActivity(Intent(this, LoginActivity::class.java)); finish()
    }

    private fun showMsg(msg: String) {
        android.widget.Toast.makeText(this, msg, android.widget.Toast.LENGTH_LONG).show()
    }
}
