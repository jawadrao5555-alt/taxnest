package pk.taxnest.rider

import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.Worker
import androidx.work.WorkerParameters
import androidx.work.WorkManager
import org.json.JSONArray
import java.util.concurrent.TimeUnit

/**
 * Background delivery check (v1.4.0) — WorkManager periodic job (15 min, the
 * platform minimum) so the rider gets a notification for newly assigned bills
 * even when the app is CLOSED and duty is OFF.  Completely separate from the
 * GPS/duty foreground service; read-only GET /me, no location involved.
 * Token gone → job quietly no-ops (and cancels on logout anyway).
 */
class DeliveryCheckWorker(ctx: Context, params: WorkerParameters) : Worker(ctx, params) {

    override fun doWork(): Result {
        val c = applicationContext
        val token = Prefs.token(c) ?: return Result.success()
        val (code, body) = ApiClient.get("/me", token)
        if (code == 401) {
            // Token rotated by a login on another device — stop checking.
            Prefs.clearToken(c)
            return Result.success()
        }
        if (code in 200..299 && body?.optBoolean("ok") == true) {
            DeliveryNotifier.process(c, body.optJSONArray("deliveries") ?: JSONArray())
        }
        return Result.success()
    }

    companion object {
        private const val WORK = "delivery_check"

        fun schedule(c: Context) {
            try {
                val req = PeriodicWorkRequestBuilder<DeliveryCheckWorker>(15, TimeUnit.MINUTES)
                    .setConstraints(
                        Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
                    )
                    .build()
                WorkManager.getInstance(c.applicationContext)
                    .enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP, req)
            } catch (e: Exception) {}
        }

        fun cancel(c: Context) {
            try {
                WorkManager.getInstance(c.applicationContext).cancelUniqueWork(WORK)
            } catch (e: Exception) {}
        }
    }
}
