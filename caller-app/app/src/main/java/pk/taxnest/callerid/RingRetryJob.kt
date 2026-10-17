package pk.taxnest.callerid

import android.app.job.JobInfo
import android.app.job.JobParameters
import android.app.job.JobScheduler
import android.app.job.JobService
import android.content.ComponentName
import android.content.Context
import android.util.Log
import java.util.concurrent.atomic.AtomicBoolean
import kotlin.concurrent.thread

/** Android-owned network retry survives receiver/process death and device reboot. */
class RingRetryJob : JobService() {
    private var stopped = AtomicBoolean(false)
    override fun onStartJob(params: JobParameters): Boolean {
        val cancellation = AtomicBoolean(false)
        stopped = cancellation
        thread(name = "caller-ring-job") {
            val retry = RingReporter.drain(applicationContext) { cancellation.get() }
            if (!cancellation.get()) jobFinished(params, retry)
        }
        return true
    }
    override fun onStopJob(params: JobParameters): Boolean {
        stopped.set(true)
        return true
    }
    companion object {
        private const val JOB_ID = 174018
        fun schedule(context: Context) {
            try {
                val scheduler = context.getSystemService(Context.JOB_SCHEDULER_SERVICE) as JobScheduler
                if (scheduler.getPendingJob(JOB_ID) != null) return
                val job = JobInfo.Builder(JOB_ID, ComponentName(context, RingRetryJob::class.java))
                    .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                    .setMinimumLatency(30_000L)
                    .setBackoffCriteria(30_000L, JobInfo.BACKOFF_POLICY_EXPONENTIAL)
                    .setPersisted(true).build()
                if (scheduler.schedule(job) != JobScheduler.RESULT_SUCCESS)
                    Log.w("CallerOutbox", "Android could not schedule queue retry; reopen app to retry")
            } catch (e: Exception) { Log.w("CallerOutbox", "Queue retry scheduling unavailable", e) }
        }
    }
}
