package pk.taxnest.rider

import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.Worker
import androidx.work.WorkerParameters
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/**
 * Background point sync + duty watchdog (v1.7.0, Task #1359).
 *
 * Before this worker the buffered GPS queue only ever left the phone in three
 * situations: the duty foreground service was alive, the rider opened the app,
 * or connectivity returned WHILE the app was in the foreground.  On phones
 * whose battery saver freezes background apps (Infinix/Tecno/Vivo/Oppo/Xiaomi)
 * none of those happen mid-route, so the whole shift uploaded in one burst
 * when the rider got back to the shop — the exact bug the owner reported.
 *
 * Same pattern as DeliveryCheckWorker (15-min periodic, network-constrained,
 * KEEP policy): WorkManager survives process death and app-freeze far better
 * than our own service, so it is the reliable floor under the tracking stack.
 *
 * Each run:
 *  1. watchdog — duty ON but tracking dead → restart it (or notify the rider);
 *  2. drain    — push whatever is buffered, so points reach the map on their
 *                own capture timestamps without the app being opened.
 */
class SyncWorker(ctx: Context, params: WorkerParameters) : Worker(ctx, params) {

    override fun doWork(): Result {
        val c = applicationContext
        Prefs.token(c) ?: return Result.success() // logged out — quiet no-op

        // 1. Tracking must be alive whenever duty is ON.
        if (Prefs.duty(c)) {
            DutyWatchdog.ensureRunning(c)
        }

        // 2. Ship the buffer. Safe alongside a live TrackingService flush —
        //    both go through QueueDrain.uploadLock.
        QueueDrain.drainBlocking(c)

        return Result.success()
    }

    companion object {
        private const val WORK = "point_sync"
        private const val WORK_NOW = "point_sync_now"

        private fun netConstraint() =
            Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

        /** Idempotent — call on login, app-open and boot. */
        fun schedule(c: Context) {
            try {
                val req = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
                    .setConstraints(netConstraint())
                    .build()
                WorkManager.getInstance(c.applicationContext)
                    .enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP, req)
            } catch (e: Exception) {}
        }

        /**
         * One-shot run as soon as there is network — used by the server's
         * "sync now" push and by the app when it notices a stale sync.
         * REPLACE policy: a burst of pushes must not queue a burst of runs.
         */
        fun runNow(c: Context) {
            try {
                val req = OneTimeWorkRequestBuilder<SyncWorker>()
                    .setConstraints(netConstraint())
                    .build()
                WorkManager.getInstance(c.applicationContext)
                    .enqueueUniqueWork(WORK_NOW, ExistingWorkPolicy.REPLACE, req)
            } catch (e: Exception) {}
        }

        fun cancel(c: Context) {
            try {
                val wm = WorkManager.getInstance(c.applicationContext)
                wm.cancelUniqueWork(WORK)
                wm.cancelUniqueWork(WORK_NOW)
            } catch (e: Exception) {}
        }
    }
}
