package pk.taxnest.rider

import android.content.Intent
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.Uri
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.LayoutInflater
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.RadioGroup
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import android.Manifest
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.Color
import android.os.Build
import android.widget.ImageView
import android.widget.ScrollView
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import org.json.JSONArray
import org.json.JSONObject
import java.net.URLEncoder
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.UUID
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

    // ── GPS for proximity display on delivery cards (Task #1508) ─────────
    // We maintain a lightweight foreground-only listener here so the cards can
    // show live distance-to-destination without starting a full tracking session.
    // Only active while the activity is resumed and there are deliveries.
    private var locationManager: LocationManager? = null
    @Volatile private var lastRiderLat: Double? = null
    @Volatile private var lastRiderLng: Double? = null
    @Volatile private var lastRiderAccM: Float? = null
    @Volatile private var lastRiderCapturedAtMs: Long? = null

    private val gpsListener = object : LocationListener {
        override fun onLocationChanged(location: Location) {
            // Ignore fixes with terrible accuracy (same threshold as TrackingService).
            if (location.hasAccuracy() && location.accuracy > 150f) return
            lastRiderLat = location.latitude
            lastRiderLng = location.longitude
            lastRiderAccM = if (location.hasAccuracy()) location.accuracy else null
            lastRiderCapturedAtMs = location.time.takeIf { it > 0L } ?: System.currentTimeMillis()
            // Refresh card proximity chips on the main thread.
            ui.post { refreshProximityChips() }
        }
        @Deprecated("Deprecated in Java")
        override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
        override fun onProviderEnabled(provider: String) {}
        override fun onProviderDisabled(provider: String) {}
    }

    // Holds the current deliveries JSONArray so proximity chips can be
    // refreshed from GPS callbacks without another /me call.
    private var currentDeliveries = JSONArray()
    // Guards asynchronous preview replies against /me replacement,
    // reassignment, logout, and Activity lifecycle changes.
    private val billPreviewSafety = BillPreviewSafety()

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
            // Reconcile pending duty-off first (idempotent), then drain queues.
            thread(name = "connectivity-restore") {
                reconcilePendingDutyOff()
                QueueDrain.drainAsync(this@MainActivity)
                // Task #1508: drain delivery completion outbox on reconnect.
                OutboxDrain.drainBlocking(this@MainActivity)
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
            billPreviewSafety.invalidateLifecycle()
            thread { ApiClient.post("/logout", JSONObject(), Prefs.token(this)) }
            // v1.5.0 (Task #1106): kill push locally too — the /logout call
            // clears the server copy, this invalidates the device token.
            Fcm.clear()
            stopService(Intent(this, TrackingService::class.java))
            DeliveryCheckWorker.cancel(this)
            SyncWorker.cancel(this)
            OutboxWorker.cancel(this)
            DutyWatchdog.clearNotification(this)
            DeliveryArrivalCache.clear(this)
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
        billPreviewSafety.resume()
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
        // Task #1508: drain delivery completion outbox on resume.
        thread(name = "outbox-resume") { OutboxDrain.drainBlocking(this) }

        // v1.7.0: duty ON but the phone killed the service while we were away
        // → bring tracking back now that we are in the foreground (a start
        // from here is always allowed, even on Android 12+).
        if (Prefs.duty(this)) DutyWatchdog.ensureRunning(this)

        // Register connectivity callback so drain fires immediately when
        // signal returns, not just on the next 30s /me poll.
        registerConnectivityCallback()

        // Task #1508: start a lightweight GPS listener for proximity display.
        startProximityGps()
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
        billPreviewSafety.invalidateLifecycle()
        ui.removeCallbacks(localStateLoop)
        ui.removeCallbacks(meRefreshLoop)
        unregisterConnectivityCallback()
        stopProximityGps()
    }

    override fun onDestroy() {
        billPreviewSafety.invalidateLifecycle()
        super.onDestroy()
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

    // ── Lightweight GPS for proximity display (Task #1508) ────────────────

    private fun startProximityGps() {
        // Duty tracking already owns GPS. A second foreground listener doubles
        // GNSS/radio callbacks without improving required server tracking.
        if (Prefs.duty(this) || currentDeliveries.length() == 0) return
        stopProximityGps()
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION)
            != PackageManager.PERMISSION_GRANTED) return
        try {
            val lm = getSystemService(LOCATION_SERVICE) as? LocationManager ?: return
            locationManager = lm
            val minTime = 10_000L  // 10s — enough for fresh display, gentle on battery
            val minDist = 10f      // 10 m
            if (lm.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, minTime, minDist, gpsListener)
            }
            if (lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, minTime * 3, minDist * 3, gpsListener)
            }
            // Seed from last known fix so chips are populated immediately on open.
            val seed = try { lm.getLastKnownLocation(LocationManager.GPS_PROVIDER) } catch (e: Exception) { null }
                ?: try { lm.getLastKnownLocation(LocationManager.NETWORK_PROVIDER) } catch (e: Exception) { null }
            if (seed != null && (!seed.hasAccuracy() || seed.accuracy <= 150f)) {
                lastRiderLat = seed.latitude
                lastRiderLng = seed.longitude
                lastRiderAccM = if (seed.hasAccuracy()) seed.accuracy else null
                refreshProximityChips()
            }
        } catch (e: Exception) {}
    }

    private fun stopProximityGps() {
        try { locationManager?.removeUpdates(gpsListener) } catch (e: Exception) {}
        locationManager = null
    }

    /** Iterates every visible delivery card and refreshes its proximity chip. */
    private fun refreshProximityChips() {
        val arr = currentDeliveries
        for (i in 0 until deliveriesContainer.childCount) {
            val row = deliveriesContainer.getChildAt(i) ?: continue
            val item = arr.optJSONObject(i) ?: continue
            val meta = DestinationMeta.from(item)
            if (!meta.hasCoords) continue
            updateCardProximity(row, item, meta)
        }
    }

    private fun updateCardProximity(row: View, item: JSONObject, meta: DestinationMeta) {
        val chip = row.findViewById<TextView>(R.id.proximityChip) ?: return
        val banner = row.findViewById<TextView>(R.id.arrivalBanner) ?: return
        val deliveredBtn = row.findViewById<Button>(R.id.deliveredBtn) ?: return

        val dist = ArrivalDetector.distanceTo(lastRiderLat, lastRiderLng, meta)
        val isNear = ArrivalDetector.isNearArrival(lastRiderLat, lastRiderLng, lastRiderAccM, meta)

        if (dist != null) {
            chip.visibility = View.VISIBLE
            chip.text = if (dist < 1000f) {
                getString(R.string.proximity_m, dist.toInt())
            } else {
                getString(R.string.proximity_km, dist / 1000f)
            }
        } else {
            chip.visibility = View.GONE
        }

        // Prominent arrival banner.
        if (isNear) {
            banner.visibility = View.VISIBLE
            banner.text = getString(R.string.arrival_banner)
            // Pull the delivered button up visually by making it more prominent.
            deliveredBtn.textSize = 16f
            // Also fire arrival notification (deduped).
            val txnId = item.optInt("id", 0)
            val rev = meta.assignmentRevision
            val inv = item.optString("invoice_number").ifBlank { "#$txnId" }
            thread(name = "arrival-notif") {
                ArrivalNotifier.checkAndNotify(
                    this, txnId, rev, inv,
                    lastRiderLat, lastRiderLng, lastRiderAccM, meta
                )
            }
        } else {
            banner.visibility = View.GONE
        }
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
        // Reuse the duty service's fix for foreground proximity UI rather than
        // registering a second location listener while tracking is active.
        TrackingService.latestFix()?.let { fix ->
            if (fix.capturedAtMs > (lastRiderCapturedAtMs ?: 0L)) {
                lastRiderLat = fix.lat
                lastRiderLng = fix.lng
                lastRiderAccM = fix.accuracyM
                lastRiderCapturedAtMs = fix.capturedAtMs
                refreshProximityChips()
            }
        }
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
        // /me is authoritative: reassignment invalidates a queued completion
        // immediately instead of leaving WorkManager to send a stale request.
        DeliveryOutbox.retainAssignments(
            this,
            DeliveryAssignmentSafety.currentAssignments(deliveriesArr)
        )
        // Nayi assigned delivery → phone par awaz ke saath ittila
        // (background thread — notifications are thread-safe).
        DeliveryNotifier.process(this, deliveriesArr)
        // Task #1508: update the arrival cache so TrackingService can check
        // proximity on GPS callbacks even when the app is in the background.
        DeliveryArrivalCache.set(this, deliveriesArr)
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
        // This increment invalidates any response started by the old cards,
        // even when an id/revision pair happens to appear in both payloads.
        billPreviewSafety.replaceCards(currentPreviewAssignments(arr))
        currentDeliveries = arr
        deliveriesContainer.removeAllViews()

        if (arr.length() == 0) {
            stopProximityGps()
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

            // ── Navigation (Task #1508) ─────────────────────────────────────
            val meta = DestinationMeta.from(item)
            val mapsUrl = item.optString("maps_url")
            val address = item.optString("address")
            val addrView = row.findViewById<TextView>(R.id.address)
            if (address.isNotBlank()) {
                addrView.text = getString(R.string.address_prefix, address)
                addrView.visibility = View.VISIBLE
                // Address tap: prefer exact-coord nav, fall back to mapsUrl, then text search.
                addrView.setOnClickListener { openNavigation(meta, mapsUrl, address) }
            } else {
                addrView.visibility = View.GONE
            }

            // Navigate button — shown when we have exact coordinates or a maps_url.
            val navBtn = row.findViewById<Button>(R.id.navigateBtn)
            if (meta.hasCoords || mapsUrl.isNotBlank() || address.isNotBlank()) {
                navBtn.visibility = View.VISIBLE
                navBtn.setOnClickListener { openNavigation(meta, mapsUrl, address) }
            } else {
                navBtn.visibility = View.GONE
            }

            // Assigned X min ago
            val mins = item.optInt("assigned_mins", -1)
            val minsView = row.findViewById<TextView>(R.id.assignedMins)
            minsView.text = if (mins >= 0) getString(R.string.assigned_mins_ago, mins)
                            else getString(R.string.assigned_just_now)

            // Proximity chip + arrival banner (Task #1508)
            if (meta.hasCoords) {
                updateCardProximity(row, item, meta)
            }

            // Delivered button (v1.6.0, Task #1160) — /me only lists
            // assigned/dispatched bills, but gate on status anyway so a future
            // payload change can't show the button on a terminal-state bill.
            val status = item.optString("status")
            val deliveredBtn = row.findViewById<Button>(R.id.deliveredBtn)
            val previewBtn = row.findViewById<Button>(R.id.billPreviewBtn)
            if (status == "assigned" || status == "dispatched") {
                deliveredBtn.visibility = View.VISIBLE
                deliveredBtn.setOnClickListener { showDeliveryConfirmDialog(item, deliveredBtn) }
                previewBtn.visibility = View.VISIBLE
                previewBtn.setOnClickListener { loadBillPreview(item, previewBtn) }
            } else {
                deliveredBtn.visibility = View.GONE
                previewBtn.visibility = View.GONE
            }

            deliveriesContainer.addView(row)
        }
        // Off duty there is no TrackingService fix source, so keep the
        // foreground-only low-rate listener only while cards need it.
        if (!Prefs.duty(this)) startProximityGps() else stopProximityGps()
    }

    // ── Navigation (Task #1508) ────────────────────────────────────────────

    /**
     * Opens Google Maps turn-by-turn navigation.
     *
     * Priority:
     *  1. Exact coordinates (destination_lat/lng from /me) → most accurate.
     *  2. maps_url from server (pre-built Google Maps link) → fallback.
     *  3. Address text search → last resort.
     */
    private fun openNavigation(meta: DestinationMeta, mapsUrl: String, address: String) {
        val uri: Uri = when {
            meta.hasCoords -> {
                // google.navigation:q=lat,lng  — opens turn-by-turn directly.
                Uri.parse("google.navigation:q=${meta.lat},${meta.lng}")
            }
            mapsUrl.isNotBlank() -> Uri.parse(mapsUrl)
            address.isNotBlank() -> {
                // Text search fallback — works with any maps app.
                Uri.parse("geo:0,0?q=${Uri.encode(address)}")
            }
            else -> return
        }
        val intent = Intent(Intent.ACTION_VIEW, uri)
        // Prefer Google Maps for coordinate nav so the app:// scheme is handled.
        intent.setPackage("com.google.android.apps.maps")
        try {
            startActivity(intent)
        } catch (e: Exception) {
            // Google Maps not installed — try any maps app (remove package constraint).
            intent.setPackage(null)
            try {
                startActivity(intent)
            } catch (e2: Exception) {
                // Last resort: open address in browser if we have a maps_url.
                if (mapsUrl.isNotBlank()) {
                    try { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(mapsUrl))) }
                    catch (e3: Exception) { showMsg(getString(R.string.navigate_no_maps)) }
                } else {
                    showMsg(getString(R.string.navigate_no_maps))
                }
            }
        }
    }

    // ── Bill preview (v1.7.2 beta) ─────────────────────────────────────────

    /** Extracts only assignments that currently qualify for a preview button. */
    private fun currentPreviewAssignments(deliveries: JSONArray): Set<BillPreviewSafety.Assignment> {
        val assignments = mutableSetOf<BillPreviewSafety.Assignment>()
        for (i in 0 until deliveries.length()) {
            val item = deliveries.optJSONObject(i) ?: continue
            val status = item.optString("status")
            val id = item.optInt("id", 0)
            val revision = item.optString("assignment_revision").trim()
            if ((status == "assigned" || status == "dispatched") && id > 0 && revision.isNotBlank()) {
                assignments.add(BillPreviewSafety.Assignment(id, revision))
            }
        }
        return assignments
    }

    /**
     * Fetches a preview only when the rider asks for it.  The revision comes
     * from this exact current /me card and is never cached, so reassignment
     * invalidates both an old card and any old preview request on the server.
     */
    private fun loadBillPreview(item: JSONObject, btn: Button) {
        val txnId = item.optInt("id", 0)
        val revision = item.optString("assignment_revision").trim()
        if (txnId <= 0 || revision.isBlank()) {
            showMsg(getString(R.string.bill_preview_unavailable))
            refreshMe()
            return
        }
        val request = billPreviewSafety.begin(txnId, revision)
        if (request == null) {
            // The card was replaced or the Activity is no longer foregrounded;
            // do not start a request for a potentially stale assignment.
            return
        }
        btn.isEnabled = false
        thread(name = "bill-preview") {
            val encodedRevision = URLEncoder.encode(revision, "UTF-8")
            val (code, body) = ApiClient.get(
                "/deliveries/$txnId/preview?revision=$encodedRevision",
                Prefs.token(this)
            )
            runOnUiThread {
                // Threads cannot safely cancel HttpURLConnection mid-read here,
                // so discard its result instead. This also avoids touching a
                // detached button after pause, logout, or reassignment.
                if (!billPreviewSafety.isCurrent(request)) return@runOnUiThread
                btn.isEnabled = true
                when {
                    code == 401 -> sessionExpired()
                    code == 403 -> showMsg(
                        body?.optString("message")?.ifBlank { null } ?: getString(R.string.plan_locked)
                    )
                    code == 404 -> {
                        // This includes a changed assignment revision. Do not
                        // retain or render an old preview; obtain fresh cards.
                        showMsg(getString(R.string.bill_preview_unavailable))
                        refreshMe()
                    }
                    code in 200..299 && body?.optBoolean("ok") == true -> {
                        val preview = body.optJSONObject("preview")
                        if (preview == null) showMsg(getString(R.string.bill_preview_failed))
                        else showBillPreviewDialog(preview)
                    }
                    code == -1 -> showMsg(getString(R.string.network_error))
                    else -> showMsg(getString(R.string.bill_preview_failed))
                }
            }
        }
    }

    /**
     * Renders exclusively the rider-preview DTO allowlist.  Do not use the
     * original delivery item here: its customer/address/payment fields are not
     * preview authorization and must never supplement this server response.
     */
    private fun showBillPreviewDialog(preview: JSONObject) {
        // A missing/null availability flag is a malformed response, not a
        // disabled preview. Only the explicit server false gets this message.
        if (!preview.has("available") || preview.isNull("available")) {
            showMsg(getString(R.string.bill_preview_failed))
            return
        }
        if (!preview.optBoolean("available")) {
            AlertDialog.Builder(this)
                .setTitle(R.string.bill_preview_title)
                .setMessage(R.string.bill_preview_disabled)
                .setPositiveButton(android.R.string.ok, null)
                .show()
            return
        }

        // grand_total is mandatory in an available DTO. Failing closed avoids
        // inventing a total from any other field if a malformed response arrives.
        val grandTotal = previewValue(preview, "grand_total")
        if (grandTotal == null) {
            showMsg(getString(R.string.bill_preview_failed))
            return
        }

        val content = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            val padding = (16 * resources.displayMetrics.density).toInt()
            setPadding(padding, padding, padding, padding)
        }
        val items = preview.optJSONArray("items")
        // Every available DTO item must carry a meaningful name. Reject the
        // full malformed preview rather than rendering a blank line or guessing
        // an item name from the delivery card.
        if (items == null) {
            showMsg(getString(R.string.bill_preview_failed))
            return
        }
        val itemLines = ArrayList<JSONObject>()
        val itemNames = ArrayList<String?>()
        for (i in 0 until items.length()) {
            val line = items.optJSONObject(i)
            if (line == null) {
                showMsg(getString(R.string.bill_preview_failed))
                return
            }
            itemLines.add(line)
            itemNames.add(previewValue(line, "name")?.trim())
        }
        if (!BillPreviewSafety.hasRequiredItemNames(itemNames)) {
            showMsg(getString(R.string.bill_preview_failed))
            return
        }
        for (i in itemLines.indices) {
            val line = itemLines[i]
            // Checked above: this is a real, nonblank server-provided name.
            addPreviewLine(content, getString(R.string.bill_preview_item, itemNames[i]!!))
            previewValue(line, "quantity")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_quantity, it))
            }
            previewValue(line, "unit_rate")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_unit_rate, formatPreviewAmount(it)))
            }
            previewValue(line, "line_total")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_line_total, formatPreviewAmount(it)))
            }
        }

        preview.optJSONObject("tax")?.let { tax ->
            previewValue(tax, "rate")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_tax_rate, it))
            }
            previewValue(tax, "amount")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_tax_amount, formatPreviewAmount(it)))
            }
        }
        previewValue(preview, "ntn")?.let {
            addPreviewLine(content, getString(R.string.bill_preview_ntn, it))
        }
        preview.optJSONObject("customer")?.let { customer ->
            previewValue(customer, "name")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_customer_name, it))
            }
            previewValue(customer, "phone")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_customer_phone, it))
            }
            previewValue(customer, "address")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_customer_address, it))
            }
        }
        preview.optJSONObject("business")?.let { business ->
            previewValue(business, "name")?.let {
                addPreviewLine(content, getString(R.string.bill_preview_business, it))
            }
        }

        val qr = preview.optJSONObject("qr")
        val qrPayload = qr?.let { previewValue(it, "payload") }
        if (qr?.optBoolean("available", false) == true && !qrPayload.isNullOrBlank()) {
            localQrBitmap(qrPayload)?.let { bitmap ->
                addPreviewLine(content, getString(R.string.bill_preview_qr))
                content.addView(ImageView(this).apply {
                    setImageBitmap(bitmap)
                    contentDescription = getString(R.string.bill_preview_qr_description)
                    adjustViewBounds = true
                    val size = (180 * resources.displayMetrics.density).toInt()
                    layoutParams = LinearLayout.LayoutParams(size, size)
                })
            }
        }

        addPreviewLine(content, getString(R.string.bill_preview_grand_total, formatPreviewAmount(grandTotal)), true)
        AlertDialog.Builder(this)
            .setTitle(R.string.bill_preview_title)
            .setView(ScrollView(this).apply { addView(content) })
            .setPositiveButton(android.R.string.ok, null)
            .show()
    }

    private fun addPreviewLine(container: LinearLayout, text: String, bold: Boolean = false) {
        container.addView(TextView(this).apply {
            this.text = text
            textSize = if (bold) 16f else 14f
            if (bold) setTypeface(typeface, android.graphics.Typeface.BOLD)
            setPadding(0, 3, 0, 3)
        })
    }

    /** Returns null for absent/null keys; never substitutes a local value. */
    private fun previewValue(source: JSONObject, key: String): String? {
        if (!source.has(key) || source.isNull(key)) return null
        return source.opt(key)?.takeUnless { it == JSONObject.NULL }?.toString()
    }

    private fun formatPreviewAmount(value: String): String =
        value.toDoubleOrNull()?.let { String.format(Locale.US, "%,.2f", it) } ?: value

    /** The payload is encoded locally and is never replaced with transaction data. */
    private fun localQrBitmap(payload: String): Bitmap? = try {
        val matrix = QRCodeWriter().encode(payload, BarcodeFormat.QR_CODE, 512, 512)
        Bitmap.createBitmap(matrix.width, matrix.height, Bitmap.Config.ARGB_8888).also { bitmap ->
            for (y in 0 until matrix.height) for (x in 0 until matrix.width) {
                bitmap.setPixel(x, y, if (matrix[x, y]) Color.BLACK else Color.WHITE)
            }
        }
    } catch (e: Exception) {
        null
    }

    // ── Delivered button (Task #1508 extended, v1.6.0 original) ──────────

    /**
     * Extended delivery confirmation dialog (Task #1508):
     * — place type: Home / Business / Other
     * — optional free-text label (≤80 chars)
     * — captures current GPS position as evidence
     * Replaces the old simple confirm dialog.
     */
    private fun showDeliveryConfirmDialog(item: JSONObject, btn: Button) {
        val billNo = item.optString("invoice_number").ifBlank { "#${item.optInt("id")}" }
        val customer = item.optString("customer_name").ifBlank { getString(R.string.unknown_customer) }
        val meta = DestinationMeta.from(item)

        val dialogView = LayoutInflater.from(this).inflate(R.layout.dialog_delivery_confirm, null)
        dialogView.findViewById<TextView>(R.id.dialogBillInfo).text =
            getString(R.string.delivered_confirm_msg, "$billNo · $customer")
        val placeGroup = dialogView.findViewById<RadioGroup>(R.id.placeTypeGroup)
        val labelEdit = dialogView.findViewById<EditText>(R.id.placeLabel)

        // Pre-select place type from server metadata if available.
        when (meta.placeType) {
            "home" -> placeGroup.check(R.id.placeHome)
            "business" -> placeGroup.check(R.id.placeBusiness)
            "other" -> placeGroup.check(R.id.placeOther)
            else -> placeGroup.check(R.id.placeHome)
        }
        if (!meta.placeLabel.isNullOrBlank()) {
            labelEdit.setText(meta.placeLabel)
        }

        AlertDialog.Builder(this)
            .setTitle(R.string.delivered_confirm_title)
            .setView(dialogView)
            .setPositiveButton(R.string.delivered_yes) { _, _ ->
                val label = labelEdit.text.toString().trim()
                if (label.length > 80) {
                    showMsg(getString(R.string.confirm_label_too_long))
                    return@setPositiveButton
                }
                val placeType = when (placeGroup.checkedRadioButtonId) {
                    R.id.placeHome -> "home"
                    R.id.placeBusiness -> "business"
                    else -> "other"
                }
                submitDelivery(item, btn, placeType, label, meta)
            }
            .setNegativeButton(R.string.delivered_no, null)
            .show()
    }

    /**
     * Builds the outbox entry, enqueues it durably, then tries an immediate
     * upload.  If the upload succeeds the outbox entry is removed inline and
     * the /me payload re-renders the screen.  If it fails (offline / transient)
     * the entry stays in the outbox and WorkManager will retry.
     */
    private fun submitDelivery(
        item: JSONObject,
        btn: Button,
        placeType: String,
        placeLabel: String,
        meta: DestinationMeta
    ) {
        val txnId = item.optInt("id")
        val clientEventId = UUID.randomUUID().toString()

        // Snapshot GPS at the moment the rider confirmed.
        val capLat = lastRiderLat
        val capLng = lastRiderLng
        val capAcc = lastRiderAccM
        val capAt = lastRiderCapturedAtMs
        val gpsIsFresh = capAt != null && System.currentTimeMillis() - capAt in 0..120_000
        if (capLat == null || capLng == null || capAcc == null || capAcc > 150f || !gpsIsFresh) {
            btn.isEnabled = true
            showMsg(getString(R.string.delivery_gps_waiting))
            return
        }

        val entry = JSONObject().apply {
            put("txn_id", txnId)
            put("client_event_id", clientEventId)
            put("place_type", placeType)
            put("place_label", placeLabel)
            put("lat", capLat)
            put("lng", capLng)
            put("accuracy_m", capAcc.toDouble())
            put("captured_at", capAt)
            put("assignment_revision", meta.assignmentRevision)
        }

        // Persist to outbox BEFORE network call.
        DeliveryOutbox.enqueue(this, entry)
        // Schedule WorkManager retry in case this attempt fails.
        OutboxWorker.schedule(this)

        btn.isEnabled = false
        thread {
            val payload = OutboxDrain.buildDeliveryPayload(entry)
            val (code, body) = ApiClient.post("/deliveries/$txnId/delivered", payload, Prefs.token(this))
            when {
                code in 200..299 -> {
                    // Remove from outbox — delivered successfully.
                    DeliveryOutbox.remove(this, txnId)
                    // Current servers return the refreshed /me payload. Keep
                    // 2xx itself as the protocol success signal so harmless
                    // response-shape changes cannot leave a delivered event
                    // stuck in the retry queue.
                    if (body != null && body.has("deliveries")) applyMePayload(body) else refreshMe()
                    runOnUiThread { showMsg(getString(R.string.delivered_done)) }
                }
                code == 409 && body?.optString("error") == "gps_not_synced" -> {
                    // Completion GPS is valid but its location batch has not
                    // reached the server yet. Keep the exact event for retry.
                    runOnUiThread {
                        btn.isEnabled = true
                        showMsg(getString(R.string.delivered_queued))
                    }
                }
                code == 409 || code == 410 -> {
                    // Assignment changed / bill gone. Never report this as a
                    // successful delivery; remove the stale event and resync.
                    DeliveryOutbox.remove(this, txnId)
                    if (body != null && body.has("deliveries")) applyMePayload(body) else refreshMe()
                    runOnUiThread { showMsg(getString(R.string.delivered_gone)) }
                }
                code == 404 && body != null -> {
                    // Bill no longer ours (reassigned / delivered elsewhere) —
                    // payload rides on the 404 too, so resync instead of erroring.
                    DeliveryOutbox.remove(this, txnId)
                    applyMePayload(body)
                    runOnUiThread { showMsg(getString(R.string.delivered_gone)) }
                }
                code == 401 -> runOnUiThread { sessionExpired() }
                code == 403 -> {
                    // Permanent plan error — remove from outbox, show message.
                    DeliveryOutbox.remove(this, txnId)
                    runOnUiThread {
                        btn.isEnabled = true
                        showMsg(body?.optString("message")?.ifBlank { null } ?: getString(R.string.plan_locked))
                    }
                }
                code == 422 -> {
                    // This captured event cannot become valid later. Remove it
                    // so the rider can try again with a fresh fix/place.
                    DeliveryOutbox.remove(this, txnId)
                    val error = body?.optString("error")
                    runOnUiThread {
                        btn.isEnabled = true
                        showMsg(
                            when (error) {
                                "too_far" -> getString(R.string.delivery_too_far)
                                "stale_gps", "gps_mismatch", "bad_gps_time" ->
                                    getString(R.string.delivery_gps_waiting)
                                else -> getString(R.string.delivered_failed)
                            }
                        )
                    }
                }
                else -> {
                    // Offline / transient failure — entry stays in outbox, WorkManager retries.
                    runOnUiThread {
                        btn.isEnabled = true
                        showMsg(getString(R.string.delivered_queued))
                    }
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
        // A token loss is also an assignment/UI invalidation boundary: an
        // in-flight preview must not render over the login transition.
        billPreviewSafety.invalidateLifecycle()
        // v1.5.0 (Task #1106): another device logged in (app_token rotated) —
        // its login also rotated the server-side FCM token, so pushes already
        // target the new phone; this just invalidates OUR device token.
        Fcm.clear()
        stopService(Intent(this, TrackingService::class.java))
        DeliveryCheckWorker.cancel(this)
        SyncWorker.cancel(this)
        OutboxWorker.cancel(this)
        DutyWatchdog.clearNotification(this)
        DeliveryArrivalCache.clear(this)
        Prefs.clearSession(this)
        showMsg(getString(R.string.session_expired))
        startActivity(Intent(this, LoginActivity::class.java)); finish()
    }

    private fun showMsg(msg: String) {
        android.widget.Toast.makeText(this, msg, android.widget.Toast.LENGTH_LONG).show()
    }
}
