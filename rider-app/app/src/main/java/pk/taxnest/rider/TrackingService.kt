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
    }

    private lateinit var netThread: HandlerThread
    private lateinit var netHandler: Handler
    private var locationManager: LocationManager? = null

    private val flushLoop = object : Runnable {
        override fun run() {
            flush()
            netHandler.postDelayed(this, FLUSH_MS)
        }
    }

    override fun onCreate() {
        super.onCreate()
        netThread = HandlerThread("rider-net").also { it.start() }
        netHandler = Handler(netThread.looper)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startAsForeground()
        running = true
        startLocationUpdates()
        netHandler.removeCallbacks(flushLoop)
        netHandler.postDelayed(flushLoop, FLUSH_MS)
        return START_STICKY
    }

    private fun startAsForeground() {
        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= 26) {
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL, getString(R.string.notif_channel), NotificationManager.IMPORTANCE_LOW)
            )
        }
        val tap = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notif: Notification = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_rider)
            .setContentTitle(getString(R.string.notif_title))
            .setContentText(getString(R.string.notif_text))
            .setOngoing(true)
            .setContentIntent(tap)
            .build()
        if (Build.VERSION.SDK_INT >= 29) {
            ServiceCompat.startForeground(this, NOTIF_ID, notif, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NOTIF_ID, notif)
        }
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
        } catch (e: SecurityException) {
            stopSelf()
        }
    }

    override fun onLocationChanged(location: Location) {
        // Network-provider fixes with terrible accuracy are noise — skip.
        if (location.hasAccuracy() && location.accuracy > 150f) return
        PointQueue.add(
            this, location.latitude, location.longitude,
            if (location.hasAccuracy()) location.accuracy.toInt() else null
        )
        if (PointQueue.size(this) >= FLUSH_AT_POINTS) {
            netHandler.post { flush() }
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
    override fun onProviderEnabled(provider: String) {}
    override fun onProviderDisabled(provider: String) {}

    /** Upload one batch; server responses steer local state. */
    private fun flush() {
        val token = Prefs.token(this) ?: run { stopSelf(); return }
        val batch = PointQueue.peekBatch(this, 100)
        if (batch.length() == 0) return

        val (code, _) = ApiClient.post("/locations", JSONObject().put("points", batch), token)
        when {
            code in 200..299 -> {
                PointQueue.removeFirst(this, batch.length())
                Prefs.setLastSync(this, System.currentTimeMillis())
            }
            code == 401 -> {
                // Token was rotated (rider logged in on another device).
                // Evict token + duty state but KEEP the queue so points can be
                // uploaded after the rider re-logs in on this device.
                Prefs.clearToken(this)
                stopSelf()
            }
            code == 403 -> { // plan downgraded — stop cleanly, keep session
                Prefs.setDuty(this, false)
                stopSelf()
            }
            code == 409 -> { // server says duty off — align
                Prefs.setDuty(this, false)
                stopSelf()
            }
            // network failure / other transient error → keep buffer, try next loop
        }
    }

    override fun onDestroy() {
        running = false
        try { locationManager?.removeUpdates(this) } catch (e: Exception) {}
        netHandler.removeCallbacks(flushLoop)
        netHandler.post { flush() } // best-effort final drain
        netThread.quitSafely()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null
}
