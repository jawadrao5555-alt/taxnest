package pk.taxnest.rider

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.Worker
import androidx.work.WorkerParameters
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/**
 * WorkManager worker that drains the delivery-completion outbox (Task #1508).
 *
 * Scheduled as a one-shot job with exponential back-off whenever an entry is
 * added to the outbox and as a REPLACE job so a flood of taps never queues a
 * flood of runs.  The worker retries automatically (WorkManager default
 * exponential back-off) until the outbox is empty or a terminal error is hit.
 */
class OutboxWorker(ctx: Context, params: WorkerParameters) : Worker(ctx, params) {

    override fun doWork(): Result {
        val c = applicationContext
        if (Prefs.token(c) == null) return Result.success() // logged out — quiet no-op
        OutboxDrain.drainBlocking(c)
        // If there are still entries left (transient failures), ask WorkManager
        // to retry with back-off.
        return if (DeliveryOutbox.size(c) > 0) Result.retry()
        else Result.success()
    }

    companion object {
        private const val WORK = "outbox_drain"

        /**
         * Schedules a one-shot drain.  REPLACE policy: a new entry while one
         * run is already queued cancels the old and starts fresh — the new
         * entry must be included.
         */
        fun schedule(c: Context) {
            try {
                val req = OneTimeWorkRequestBuilder<OutboxWorker>()
                    .setConstraints(
                        Constraints.Builder()
                            .setRequiredNetworkType(NetworkType.CONNECTED)
                            .build()
                    )
                    .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                    .build()
                WorkManager.getInstance(c.applicationContext)
                    .enqueueUniqueWork(WORK, ExistingWorkPolicy.REPLACE, req)
            } catch (e: Exception) {
                // WorkManager not initialized yet — ignore; will be scheduled again on next enqueue.
            }
        }

        fun cancel(c: Context) {
            try {
                WorkManager.getInstance(c.applicationContext).cancelUniqueWork(WORK)
            } catch (e: Exception) {}
        }
    }
}
