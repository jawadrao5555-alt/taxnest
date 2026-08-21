package pk.taxnest.callerid

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * Task 1381 — "POS se hi call back karein" ka phone wala hissa.
 *
 * Counter ka paired phone server se poochta rehta hai ke koi dial request to
 * nahi. Request milte hi ek high-priority notification aati hai; cashier ke
 * ek tap par system dialer number ke saath khul jata hai. Call khud-ba-khud
 * kabhi nahi lagti — CALL_PHONE permission is app mein hai hi nahi (aur na
 * honi chahiye: Play Protect + owner ka faisla, ek tap zaroori hai).
 *
 * Foreground service kyun: app band hone ke baad bhi request chand second
 * mein uthni chahiye. Type = dataSync; koi blocked permission nahi
 * (FOREGROUND_SERVICE, FOREGROUND_SERVICE_DATA_SYNC, POST_NOTIFICATIONS,
 * RECEIVE_BOOT_COMPLETED — chaaron Play Protect ki list se bahar).
 *
 * Long-poll jaan boojh kar nahi: server cPanel PHP-FPM par hai, khuli
 * long-poll workers kha jati. Server har jawab mein `poll_ms` bhejta hai, is
 * liye raftaar app release ke baghair badli ja sakti hai.
 */
class DialWatchService : Service() {

    @Volatile private var stopped = false

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startAsForeground()
        if (!looping) {
            looping = true
            thread(isDaemon = true, name = "dial-watch") { loop() }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        stopped = true
        looping = false
        super.onDestroy()
    }

    /**
     * Task 1382: strings HAMESHA chuni hui zubaan se. Service koi Activity nahi
     * hai, is liye `BaseActivity.attachBaseContext` yahan nahi chalta — jaise
     * `UpdateCheck` ka DownloadManager receiver, yahan bhi khud wrap karna hai,
     * warna notification phone ki system language mein chali jati hai.
     */
    private val txt: Context by lazy { Lang.wrap(applicationContext) }

    private fun startAsForeground() {
        val n = Notification.Builder(this, CH_ONGOING)
            .setContentTitle(txt.getString(R.string.dial_service_title))
            .setContentText(txt.getString(R.string.dial_service_text))
            .setSmallIcon(R.drawable.ic_caller)
            .setOngoing(true)
            .build()
        try {
            if (Build.VERSION.SDK_INT >= 29) {
                startForeground(ONGOING_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
            } else {
                startForeground(ONGOING_ID, n)
            }
        } catch (e: Exception) {
            // FGS start na ho saka (nadir: background start pabandi) — service
            // ko marne dein, agli screen-resume par dobara chalegi.
            stopSelf()
        }
    }

    private fun loop() {
        var wait = 5000L
        while (!stopped) {
            val token = Prefs.token(this)
            if (token == null) {
                // Sign-out ho gaya — service ka koi kaam nahi.
                stopSelf(); return
            }
            // Har poll ke saath sach batao: yeh phone offer DIKHA bhi sakta hai
            // ya nahi. Android 13+ par POST_NOTIFICATIONS na ho (ya user ne
            // settings/channel se band kar diya ho) to notify() koi error nahi
            // deta, notification bas ghayab ho jati hai. Server ko yeh na
            // batayen to POS jhoota "bhej diya" dikha kar cashier ko dead end
            // par chhor dega — is liye flag hamesha jata hai.
            val (code, json) = ApiClient.get("/dial-requests?notif=" + (if (canShowOffer()) "1" else "0"), token)
            when {
                code == 401 -> { stopSelf(); return }        // token revoke/expire
                code in 200..299 && json != null -> {
                    wait = json.optLong("poll_ms", 5000L).coerceIn(2000L, 120000L)
                    val arr = json.optJSONArray("requests")
                    if (arr != null) {
                        for (i in 0 until arr.length()) {
                            arr.optJSONObject(i)?.let { offer(it, token) }
                        }
                    }
                }
                // Network/server masla — thoda peeche hatein, magar itna nahi
                // ke call back bekaar ho jaye.
                else -> wait = minOf(wait * 2, 60000L).coerceAtLeast(10000L)
            }
            var slept = 0L
            while (!stopped && slept < wait) {
                try { Thread.sleep(500) } catch (e: InterruptedException) { return }
                slept += 500
            }
        }
    }

    private fun canShowOffer(): Boolean = offersVisible(this)

    /** Ek dial request ko tap-to-dial notification bana kar dikhana. */
    private fun offer(req: JSONObject, token: String) {
        val id = req.optInt("id", 0)
        val dial = req.optString("dial", "")
        if (id <= 0 || dial.isBlank()) return
        // Der se aane wali (budhi) request par kabhi call na lage.
        if (req.optInt("expires_in", 0) <= 0) { report(id, "failed", "expired", token); return }

        val display = req.optString("display", dial)
        val name = req.optString("name", "").takeIf { it.isNotBlank() && it != "null" }

        val tap = Intent(this, DialActivity::class.java).apply {
            action = Intent.ACTION_VIEW           // har request ka apna PendingIntent
            data = android.net.Uri.parse("taxnestdial://req/$id")
            putExtra(DialActivity.EXTRA_ID, id)
            putExtra(DialActivity.EXTRA_DIAL, dial)
            putExtra(DialActivity.EXTRA_NOTIF_ID, NOTIF_BASE + id)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }
        val pi = PendingIntent.getActivity(
            this, id, tap,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val n = Notification.Builder(this, CH_OFFER)
            .setContentTitle(txt.getString(R.string.dial_notif_title))
            .setContentText(if (name != null) "$name · $display" else display)
            .setSmallIcon(R.drawable.ic_caller)
            .setContentIntent(pi)
            .setAutoCancel(true)
            .setCategory(Notification.CATEGORY_CALL)
            .setVisibility(Notification.VISIBILITY_PUBLIC)
            .addAction(
                Notification.Action.Builder(
                    android.graphics.drawable.Icon.createWithResource(this, R.drawable.ic_caller),
                    txt.getString(R.string.dial_action), pi
                ).build()
            )
            // Request server par bhi expire ho jati hai — notification ko us se
            // zyada der latka mat rakho, warna cashier purani par tap kar de.
            .setTimeoutAfter(req.optInt("expires_in", 60).coerceIn(15, 300) * 1000L)
            .build()

        val nm = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        try {
            nm.notify(NOTIF_BASE + id, n)
        } catch (e: Exception) {
            report(id, "failed", "notify_failed", token)
        }
    }

    private fun report(id: Int, status: String, error: String?, token: String) {
        try {
            val body = JSONObject().put("id", id).put("status", status)
            if (error != null) body.put("error", error)
            ApiClient.post("/dial-result", body, token)
        } catch (e: Exception) { /* best-effort */ }
    }

    companion object {
        const val CH_ONGOING = "dial_watch"
        const val CH_OFFER = "dial_offer"
        private const val ONGOING_ID = 4180
        private const val NOTIF_BASE = 41800

        @Volatile private var looping = false

        /**
         * Kya tap-to-dial notification is waqt sach mein nazar aayegi?
         *
         * Do alag rukawatein dekhni parti hain — dono khamosh hain:
         *  1. App ki notifications hi band (Android 13+ ki permission mana kar
         *     di, ya user ne settings se off kar di) → `areNotificationsEnabled`.
         *  2. App to allowed hai magar isi channel ko user ne band kar diya →
         *     channel ki importance NONE.
         */
        fun offersVisible(ctx: Context): Boolean = try {
            val nm = ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            val appOn = nm.areNotificationsEnabled()
            val channelOn = if (Build.VERSION.SDK_INT >= 26) {
                val ch = nm.getNotificationChannel(CH_OFFER)
                ch == null || ch.importance != NotificationManager.IMPORTANCE_NONE
            } else true
            appOn && channelOn
        } catch (e: Exception) {
            // Haalat maloom nahi — jhoot bolne se behtar hai ke POS number copy
            // karwa de.
            false
        }

        fun createChannels(ctx: Context) {
            if (Build.VERSION.SDK_INT < 26) return
            val nm = ctx.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            val txt = Lang.wrap(ctx.applicationContext)   // channel naam bhi chuni hui zubaan mein
            // MIN: yeh sirf "app zinda hai" wali khamosh line hai.
            nm.createNotificationChannel(
                NotificationChannel(CH_ONGOING, txt.getString(R.string.dial_channel_watch), NotificationManager.IMPORTANCE_MIN)
            )
            // HIGH: heads-up — counter par cashier ko foran nazar aani chahiye.
            nm.createNotificationChannel(
                NotificationChannel(CH_OFFER, txt.getString(R.string.dial_channel_offer), NotificationManager.IMPORTANCE_HIGH)
            )
        }

        /** Signed-in ho to service chalti rakho (call idempotent hai). */
        fun ensureRunning(ctx: Context) {
            if (Prefs.token(ctx) == null) return
            try {
                createChannels(ctx)
                val i = Intent(ctx.applicationContext, DialWatchService::class.java)
                if (Build.VERSION.SDK_INT >= 26) ctx.applicationContext.startForegroundService(i)
                else ctx.applicationContext.startService(i)
            } catch (e: Exception) { /* Android ne background start rok diya — agli screen par phir */ }
        }
    }
}
