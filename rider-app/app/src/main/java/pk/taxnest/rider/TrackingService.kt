package pk.taxnest.rider

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.HandlerThread
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import org.json.JSONObject

/**
 * Foreground location service — the heart of the app.
 *
 * - LocationManager (GPS + network) — zero Play-Services dependency, works on
 *   every Android phone in the market.
 * - Points buffer in PointQueue (offline-safe), flushed every 45s / 20 points.
 * - Server is the boss: 401/403 → end session/duty; 409 → duty already off.
 *
 * 401 handling — token-only eviction:
 *   On 401 the token has been rotated (rider logged in on another device).
 *   We evict the token and stop the service but PRESERVE the GPS point queue
 *   so that when the rider re-logs in on this device the buffered offline
 *   points drain automatically once duty resumes.
 *
 * v1.7.0 (Task #1359):
 *   - HEARTBEAT: a stationary rider produces no GPS callbacks (15 m distance
 *     filter), which used to look exactly like a dead app on the live map.
 *     Every HEARTBEAT_MS we re-queue the last known fix so silence on the
 *     server genuinely means "app or net is down".
 *   - The ongoing notification now carries the last-sync age, and turns into a
 *     warning line when nothing has reached the shop for a while.
 */
class TrackingService : Service(), LocationListener {

    companion object {
        @Volatile var running = false
        private const val NOTIF_ID = 1
        private const val CHANNEL = "duty_tracking"
        private const val FLUSH_MS = 45_000L
        private const val MIN_TIME_MS = 20_000L
        private const val MIN_DIST_M = 15f
        private const val FLUSH_AT_POINTS = 20

        /**
         * Stationary heartbeat interval. Kept well under the server's smallest
         * configurable "silent" window (3 min) so a parked rider never trips
         * the red badge. Re-sends the SAME coordinates, so distance/idle
         * analytics are untouched (0 m moved, below the 12 m jitter floor).
         */
        private const val HEARTBEAT_MS = 120_000L
    }

    private lateinit var netThread: HandlerThread
    private lateinit var netHandler: Handler
    private var locationManager: LocationManager? = null

    // Last known fix — the heartbeat's payload when GPS goes quiet.
    @Volatile private var lastLat: Double? = null
    @Volatile private var lastLng: Double? = null
    @Volatile private var lastAcc: Int? = null
    @Volatile private var lastPointAt = 0L

    private val flushLoop = object : Runnable {
        override fun run() {
            maybeHeartbeat()
            flush()
            updateNotification()
            netHandler.postDelayed(this, FLUSH_MS)
        }
    }

    override fun onCreate() {
        super.onCreate()
        netThread = HandlerThread("rider-net").also { it.start() }
        netHandler = Handler(netThread.looper)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (!startAsForeground()) {
            // We never became a LEGAL foreground service — Android 12+ blocked
            // the background start, or Android 14 refused a location-typed FGS
            // (boot / worker / push contexts, or background location denied).
            // Do NOT pretend tracking is alive: the watchdog would then trust a
            // service the system is about to kill and the rider would learn
            // nothing. startAsForeground() has already put the tap-to-resume
            // notification up; stop now (promptly, so the 5 s
            // startForegroundService deadline cannot crash us) and let the
            // rider's tap — a foreground start, always allowed — fix it.
            running = false
            stopSelf()
            return START_NOT_STICKY
        }
        running = true
        // Tracking is alive again — retire any "tap to resume" notification.
        DutyWatchdog.clearNotification(this)
        startLocationUpdates()
        netHandler.removeCallbacks(flushLoop)
        netHandler.postDelayed(flushLoop, FLUSH_MS)
        return START_STICKY
    }

    /** @return true only when the service really is foreground-promoted. */
    private fun startAsForeground(): Boolean {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= 26) {
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL, getString(R.string.notif_channel), NotificationManager.IMPORTANCE_LOW)
            )
        }
        try {
            if (Build.VERSION.SDK_INT >= 29) {
                ServiceCompat.startForeground(this, NOTIF_ID, buildNotification(), ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
            } else {
                startForeground(NOTIF_ID, buildNotification())
            }
            return true
        } catch (e: Exception) {
            // Android 14 refuses a location-typed FGS without location
            // permission; a typeless one still lets us keep the queue moving.
            try {
                startForeground(NOTIF_ID, buildNotification())
                return true
            } catch (e2: Exception) {
                // Cannot go foreground at all — hand the rider a tap-to-resume
                // notification instead of dying silently.
                DutyWatchdog.notifyResumeNeeded(this)
                return false
            }
        }
    }

    /** Ongoing duty notification — carries live sync status (v1.7.0). */
    private fun buildNotification(): Notification {
        val tap = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val late = SyncStatus.isLate(this)
        val text = if (late)
            getString(R.string.notif_text_late, SyncStatus.lastSyncLabel(this))
        else
            getString(R.string.notif_text_synced, SyncStatus.lastSyncLabel(this))
        val builder = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_rider)
            .setContentTitle(getString(R.string.notif_title))
            .setContentText(text)
            .setOngoing(true)
            .setContentIntent(tap)
        if (late) {
            builder.setStyle(
                NotificationCompat.BigTextStyle()
                    .bigText(text + "\n" + SyncStatus.reasonText(this))
            )
        }
        return builder.build()
    }

    private fun updateNotification() {
        try {
            (getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager)
                .notify(NOTIF_ID, buildNotification())
        } catch (e: Exception) {}
    }

    private fun startLocationUpdates() {
        try {
            locationManager = getSystemService(Context.LOCATION_SERVICE) as LocationManager
            val lm = locationManager ?: return
            if (lm.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, MIN_TIME_MS, MIN_DIST_M, this)
            }
            if (lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, MIN_TIME_MS * 3, MIN_DIST_M * 3, this)
            }
            // Seed the heartbeat so a rider who starts duty parked still shows
            // up on the map before his first movement.
            if (lastLat == null) {
                val seed = try { lm.getLastKnownLocation(LocationManager.GPS_PROVIDER) } catch (e: Exception) { null }
                    ?: try { lm.getLastKnownLocation(LocationManager.NETWORK_PROVIDER) } catch (e: Exception) { null }
                if (seed != null) {
                    lastLat = seed.latitude
                    lastLng = seed.longitude
                    lastAcc = if (seed.hasAccuracy()) seed.accuracy.toInt() else null
                }
            }
        } catch (e: SecurityException) {
            Prefs.setLastSyncError(this, SyncStatus.ERR_PERM)
            stopSelf()
        }
    }

    override fun onLocationChanged(location: Location) {
        // Network-provider fixes with terrible accuracy are noise — skip.
        if (location.hasAccuracy() && location.accuracy > 150f) return
        lastLat = location.latitude
        lastLng = location.longitude
        lastAcc = if (location.hasAccuracy()) location.accuracy.toInt() else null
        lastPointAt = System.currentTimeMillis()
        PointQueue.add(
            this, location.latitude, location.longitude, lastAcc, batteryPct()
        )
        if (PointQueue.size(this) >= FLUSH_AT_POINTS) {
            netHandler.post { flush() }
        }
    }

    /**
     * Stationary heartbeat (v1.7.0): no new fix for HEARTBEAT_MS → re-queue the
     * last known position with a CURRENT timestamp. Server-side this keeps
     * last_located_at fresh, so the live map's red "khamosh" badge means what
     * it says. Identical coordinates ⇒ no effect on distance or idle stats.
     */
    private fun maybeHeartbeat() {
        if (!Prefs.duty(this)) return
        val lat = lastLat ?: return
        val lng = lastLng ?: return
        val now = System.currentTimeMillis()
        if (lastPointAt != 0L && now - lastPointAt < HEARTBEAT_MS) return
        lastPointAt = now
        PointQueue.add(this, lat, lng, lastAcc, batteryPct())
    }

    /**
     * v1.5.0 (Task #1106): battery % piggybacked on each point so the admin
     * map can warn "battery kam hai". Best-effort — null on any failure
     * (server treats missing battery as "old APK", nothing breaks).
     */
    private fun batteryPct(): Int? = try {
        val bm = getSystemService(Context.BATTERY_SERVICE) as android.os.BatteryManager
        val v = bm.getIntProperty(android.os.BatteryManager.BATTERY_PROPERTY_CAPACITY)
        if (v in 1..100) v else null
    } catch (e: Exception) {
        null
    }

    @Deprecated("Deprecated in Java")
    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
    override fun onProviderEnabled(provider: String) {}
    override fun onProviderDisabled(provider: String) {}

    /** Upload one batch; server responses steer local state. */
    private fun flush() {
        val token = Prefs.token(this) ?: run { stopSelf(); return }
        // Shared with QueueDrain: the background worker may drain the same
        // queue while we are running (v1.7.0) — never trim it twice.
        synchronized(QueueDrain.uploadLock) {
            val batch = PointQueue.peekBatch(this, 100)
            if (batch.length() == 0) return

            val (code, body) = ApiClient.post("/locations", JSONObject().put("points", batch), token)
            when {
                code in 200..299 -> {
                    // Use the server's `stored` count to trim the queue precisely.
                    // If stored==0 (all points rejected as too-old or bad coords),
                    // remove the full batch to avoid an infinite retry of permanently-
                    // invalid points.
                    val stored = body?.optInt("stored", 0) ?: 0
                    val toRemove = if (stored > 0) stored else batch.length()
                    // ackBatch (not removeFirst): live fixes keep arriving during
                    // the upload and a full buffer trims its own front.
                    PointQueue.ackBatch(this, batch, toRemove)
                    Prefs.setLastSync(this, System.currentTimeMillis())
                    Prefs.setLastSyncError(this, SyncStatus.ERR_NONE)
                }
                code == 401 -> {
                    // Token was rotated (rider logged in on another device).
                    // Evict token + duty state but KEEP the queue so points can be
                    // uploaded after the rider re-logs in on this device.
                    Prefs.clearToken(this)
                    stopSelf()
                }
                code == 403 -> {
                    // Plan downgraded — stop cleanly; keep queue so it can drain
                    // via QueueDrain if the plan is later restored.
                    Prefs.setLastSyncError(this, SyncStatus.ERR_PLAN)
                    Prefs.setDuty(this, false)
                    stopSelf()
                }
                code == 409 -> { // server says duty off — align local state
                    Prefs.setLastSyncError(this, SyncStatus.ERR_DUTY_OFF)
                    Prefs.setDuty(this, false)
                    stopSelf()
                }
                // network failure / other transient error → keep buffer, try next loop
                else -> Prefs.setLastSyncError(this, SyncStatus.ERR_NET)
            }
        }
    }

    /**
     * App swiped out of recents. On most OEMs this also kills the service, so
     * make sure the background job is scheduled to pick the queue back up and
     * restart tracking (v1.7.0).
     */
    override fun onTaskRemoved(rootIntent: Intent?) {
        if (Prefs.token(this) != null) SyncWorker.schedule(this)
        super.onTaskRemoved(rootIntent)
    }

    override fun onDestroy() {
        running = false
        try { locationManager?.removeUpdates(this) } catch (e: Exception) {}
        netHandler.removeCallbacks(flushLoop)
        // Only attempt a final flush if the service is being destroyed while
        // duty is still ON (e.g. process killed by OEM).  If duty is already
        // OFF the service stopped intentionally (409/403/manual end-duty) and
        // remaining queue points will be picked up by QueueDrain on next
        // app-open or connectivity event — firing flush here just gets a 409.
        if (Prefs.duty(this)) {
            netHandler.post { flush() }
            // Duty is still ON but tracking just died — the periodic worker is
            // the safety net that resurrects it (or tells the rider).
            SyncWorker.schedule(this)
        }
        netThread.quitSafely()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null
}
