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
    private lateinit var syncStatusText: TextView
    private lateinit var batteryChip: TextView
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
        syncStatusText     = findViewById(R.id.syncStatusText)
        batteryChip        = findViewById(R.id.batteryChip)
        welcomeText        = findViewById(R.id.welcomeText)
        summaryText        = findViewById(R.id.summaryText)
        updateRow          = findViewById(R.id.updateRow)
        deliveriesContainer = findViewById(R.id.deliveriesContainer)
        emptyDeliveriesText = findViewById(R.id.emptyDeliveriesText)

        welcomeText.text = getString(R.string.welcome, Prefs.riderName(this))

        dutyBtn.setOnClickListener { if (Prefs.duty(this)) endDuty() else beginDutyChain() }

        // v1.7.0 (Task #1359): the chip is the standing reminder for a rider
        // who skipped the battery dialog — tapping it reopens the same gate.
        batteryChip.setOnClickListener { showBatterySetupDialog(null) }

        findViewById<Button>(R.id.logoutBtn).setOnClickListener {
            thread { ApiClient.post("/logout", JSONObject(), Prefs.token(this)) }
            // v1.5.0 (Task #1106): kill push locally too — the /logout call
            // clears the server copy, this invalidates the device token.
            Fcm.clear()
            stopService(Intent(this, TrackingService::class.java))
            DeliveryCheckWorker.cancel(this)
            SyncWorker.cancel(this)
            DutyWatchdog.clearNotification(this)
            Prefs.clearSession(this)
            startActivity(Intent(this, LoginActivity::class.java)); finish()
        }

        findViewById<Button>(R.id.updateBtn).setOnClickListener {
            // Task 443: Play-Store-jaisa update — APK khud download ho kar
            // Android ka install prompt khulta hai (browser round-trip khatam).
            UpdateCheck.startDownload(this, ApiClient.DOWNLOAD_PAGE)
        }

        // v1.4.0: background delivery check — notification even when the app
        // is closed / duty off (Touseef case). KEEP policy = idempotent.
        DeliveryCheckWorker.schedule(this)
        // v1.7.0 (Task #1359): background point-sync + duty watchdog. Also
        // KEEP/idempotent — this is the safety net that keeps the route
        // flowing when the phone freezes the app mid-shift.
        SyncWorker.schedule(this)

        handleResumeIntent(intent)

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

        // v1.7.0: duty ON but the phone killed the service while we were away
        // → bring tracking back now that we are in the foreground (a start
        // from here is always allowed, even on Android 12+).
        if (Prefs.duty(this)) DutyWatchdog.ensureRunning(this)

        // Register connectivity callback so drain fires immediately when
        // signal returns, not just on the next 30s /me poll.
        registerConnectivityCallback()
    }

    override fun onNewIntent(intent: Intent?) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleResumeIntent(intent)
    }

    /**
     * Rider tapped the watchdog's "tracking has stopped" notification
     * (v1.7.0, Task #1359). We are in the foreground now, so the foreground
     * service start that Android refused in the background will succeed.
     */
    private fun handleResumeIntent(intent: Intent?) {
        if (intent?.getBooleanExtra(DutyWatchdog.EXTRA_RESUME, false) != true) return
        intent.removeExtra(DutyWatchdog.EXTRA_RESUME)
        if (Prefs.token(this) == null || !Prefs.duty(this)) {
            DutyWatchdog.clearNotification(this)
            return
        }
        if (DutyWatchdog.ensureRunning(this)) showMsg(getString(R.string.tracking_resumed))
        SyncWorker.runNow(this)
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
        if (!SyncStatus.isBatteryUnrestricted(this)) {
            showBatterySetupDialog { startDutyOnServer() }
            return
        }
        startDutyOnServer()
    }

    /**
     * Battery + autostart setup gate (v1.7.0, Task #1359).
     *
     * The standard battery-optimisation switch is only half the fix on the
     * phones our riders carry: Infinix/Tecno, Xiaomi, Oppo and Vivo also keep a
     * vendor-only "autostart / background run" list, and an app missing from it
     * gets frozen the moment the screen goes off. So the dialog offers both
     * doors, and whichever the rider takes, duty still starts (via [onDone]) —
     * a rider must never be blocked from working. If he skips it, the warning
     * chip on the duty screen keeps the fix one tap away.
     *
     * @param onDone continuation for the duty-on chain; null when the dialog
     *               was opened from the standing warning chip.
     */
    private fun showBatterySetupDialog(onDone: (() -> Unit)?) {
        val builder = AlertDialog.Builder(this)
            .setTitle(R.string.battery_dialog_title)
            .setMessage(R.string.battery_dialog_msg)
            .setPositiveButton(R.string.battery_btn) { _, _ ->
                try {
                    startActivity(Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                        Uri.parse("package:$packageName")))
                } catch (e: Exception) {
                    try { startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)) } catch (e2: Exception) {}
                }
                onDone?.invoke()
            }
            .setNegativeButton(R.string.skip) { _, _ -> onDone?.invoke() }
            .setOnCancelListener { onDone?.invoke() }
        if (AutoStartHelper.isSupported(this)) {
            builder.setNeutralButton(R.string.autostart_btn) { _, _ ->
                AutoStartHelper.open(this)
                onDone?.invoke()
            }
        }
        builder.show()
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

        renderSyncHealth(onDuty)
    }

    /**
     * "Is my location actually reaching the shop?" — answered on the home
     * screen in the rider's own words (v1.7.0, Task #1359), so he can fix
     * internet / GPS / battery on the road instead of finding out at the shop.
     */
    private fun renderSyncHealth(onDuty: Boolean) {
        if (!onDuty) {
            syncStatusText.visibility = View.GONE
        } else {
            syncStatusText.visibility = View.VISIBLE
            val ago = SyncStatus.lastSyncLabel(this)
            if (SyncStatus.isLate(this)) {
                syncStatusText.text = getString(R.string.sync_late, ago) + "\n" + SyncStatus.reasonText(this)
                syncStatusText.setTextColor(ContextCompat.getColor(this, android.R.color.holo_red_dark))
            } else {
                syncStatusText.text = getString(R.string.sync_ok, ago)
                syncStatusText.setTextColor(0xFF067647.toInt())
            }
        }
        batteryChip.visibility =
            if (SyncStatus.isBatteryUnrestricted(this)) View.GONE else View.VISIBLE
    }

    private fun refreshMe() {
        thread {
            // Reconcile any pending duty-off before reading /me so the server
            // state is consistent when we sync the duty flag below.
            reconcilePendingDutyOff()

            val (code, body) = ApiClient.get("/me", Prefs.token(this))
            if (code == 401) { runOnUiThread { sessionExpired() }; return@thread }
            if (code in 200..299 && body?.optBoolean("ok") == true) {
                applyMePayload(body)
            }
        }
    }

    /**
     * Renders a /me-shaped payload (duty flag, summary counts, deliveries
     * list). The delivered endpoint (v1.6.0) returns the same shape, so both
     * paths share this. Call from a BACKGROUND thread — the DeliveryNotifier
     * pass runs here, then UI work hops to the main thread itself.
     */
    private fun applyMePayload(body: JSONObject) {
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

            // Delivered button (v1.6.0, Task #1160) — /me only lists
            // assigned/dispatched bills, but gate on status anyway so a future
            // payload change can't show the button on a terminal-state bill.
            val status = item.optString("status")
            val deliveredBtn = row.findViewById<Button>(R.id.deliveredBtn)
            if (status == "assigned" || status == "dispatched") {
                deliveredBtn.visibility = View.VISIBLE
                deliveredBtn.setOnClickListener { confirmDelivered(item, deliveredBtn) }
            } else {
                deliveredBtn.visibility = View.GONE
            }

            deliveriesContainer.addView(row)
        }
    }

    // ── Delivered button (v1.6.0, Task #1160) ──────────────────────────────

    /** Confirm dialog before marking a bill delivered — mis-taps are cheap here. */
    private fun confirmDelivered(item: JSONObject, btn: Button) {
        val billNo = item.optString("invoice_number").ifBlank { "#${item.optInt("id")}" }
        val customer = item.optString("customer_name").ifBlank { getString(R.string.unknown_customer) }
        AlertDialog.Builder(this)
            .setTitle(R.string.delivered_confirm_title)
            .setMessage(getString(R.string.delivered_confirm_msg, "$billNo · $customer"))
            .setPositiveButton(R.string.delivered_yes) { _, _ -> markDelivered(item.optInt("id"), btn) }
            .setNegativeButton(R.string.delivered_no, null)
            .show()
    }

    private fun markDelivered(txnId: Int, btn: Button) {
        btn.isEnabled = false
        thread {
            val (code, body) = ApiClient.post("/deliveries/$txnId/delivered", JSONObject(), Prefs.token(this))
            when {
                code in 200..299 && body?.optBoolean("ok") == true -> {
                    // Response is the refreshed /me payload — one-shot re-render
                    // (the delivered card drops out, counts update).
                    applyMePayload(body)
                    runOnUiThread { showMsg(getString(R.string.delivered_done)) }
                }
                code == 404 && body != null -> {
                    // Bill no longer ours (reassigned / delivered elsewhere) —
                    // payload rides on the 404 too, so resync instead of erroring.
                    applyMePayload(body)
                    runOnUiThread { showMsg(getString(R.string.delivered_gone)) }
                }
                code == 401 -> runOnUiThread { sessionExpired() }
                code == 403 -> runOnUiThread {
                    btn.isEnabled = true
                    showMsg(body?.optString("message")?.ifBlank { null } ?: getString(R.string.plan_locked))
                }
                else -> runOnUiThread {
                    // Offline / transient failure — re-enable so the rider can retry.
                    btn.isEnabled = true
                    showMsg(getString(R.string.delivered_failed))
                }
            }
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
        // v1.5.0 (Task #1106): another device logged in (app_token rotated) —
        // its login also rotated the server-side FCM token, so pushes already
        // target the new phone; this just invalidates OUR device token.
        Fcm.clear()
        stopService(Intent(this, TrackingService::class.java))
        DeliveryCheckWorker.cancel(this)
        SyncWorker.cancel(this)
        DutyWatchdog.clearNotification(this)
        Prefs.clearSession(this)
        showMsg(getString(R.string.session_expired))
        startActivity(Intent(this, LoginActivity::class.java)); finish()
    }

    private fun showMsg(msg: String) {
        android.widget.Toast.makeText(this, msg, android.widget.Toast.LENGTH_LONG).show()
    }
}
