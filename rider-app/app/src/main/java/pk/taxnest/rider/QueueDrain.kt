package pk.taxnest.rider

import android.content.Context
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * Drains the offline GPS point queue.
 *
 * Called from:
 *  - MainActivity.onResume (every app-open while duty=OFF)
 *  - LoginActivity after a successful login
 *  - MainActivity's NetworkCallback when connectivity returns
 *  - SyncWorker (v1.7.0) — the 15-min background job that keeps points moving
 *    even when the app is closed and the duty service was killed by the phone
 *
 * The server (v1.3.0+) accepts past-timestamp buffered points (is_offline=true)
 * regardless of duty status, so this path works even when on_duty=false on
 * the server.  Fresh points (no `at` or lag < 5 min) are silently skipped by
 * the server unless duty is ON.
 *
 * Response `stored` field: the app removes exactly that many points from the
 * front of the queue so partial-accept (dedupe, 7-day rejects) is handled
 * precisely.  If stored==0 the entire batch is removed to avoid an infinite
 * retry loop for points that will never be accepted (too old, bad coords).
 */
object QueueDrain {

    /**
     * Serializes ALL uploads in the process.  TrackingService.flush() and this
     * drain both peek-then-removeFirst; running them concurrently would let two
     * uploaders trim each other's points off the queue (data loss).  Since
     * v1.7.0 the background worker can drain while the service is alive, so the
     * lock is mandatory, not just defensive.
     */
    val uploadLock = Any()

    /**
     * Fire-and-forget: spawns a background thread and returns immediately.
     * Safe to call on the main thread.  No-ops if:
     *  - no token (not logged in)
     *  - queue is empty
     *  - TrackingService is already running (it handles its own flush loop)
     */
    fun drainAsync(context: Context) {
        val c = context.applicationContext
        if (Prefs.token(c) == null) return
        if (PointQueue.size(c) == 0) return
        if (TrackingService.running) return  // service flush loop covers it

        thread(name = "queue-drain") { drainBlocking(c) }
    }

    /**
     * Blocking drain — call from a background thread only (SyncWorker, push).
     * Unlike drainAsync it runs even while TrackingService is alive: the
     * uploadLock keeps the two from trimming the same batch twice.
     */
    fun drainBlocking(context: Context) {
        val c = context.applicationContext
        val token = Prefs.token(c) ?: return
        if (PointQueue.size(c) == 0) return

        synchronized(uploadLock) {
            // Drain in batches until queue is empty or a hard error occurs.
            repeat(50) { // safety cap: max 50 × 100 = 5000 points per drain cycle
                val batch = PointQueue.peekBatch(c, 100)
                if (batch.length() == 0) return  // done

                val (code, body) = ApiClient.post(
                    "/locations",
                    JSONObject().put("points", batch),
                    token
                )
                when {
                    code in 200..299 -> {
                        val stored = body?.optInt("stored", 0) ?: 0
                        // Remove exactly `stored` validated points.  If stored==0
                        // (all rejected as too-old or bad coords), remove the full
                        // batch to prevent an infinite retry of permanently-invalid pts.
                        val toRemove = if (stored > 0) stored else batch.length()
                        PointQueue.ackBatch(c, batch, toRemove)
                        Prefs.setLastSync(c, System.currentTimeMillis())
                        Prefs.setLastSyncError(c, SyncStatus.ERR_NONE)
                        // If we received fewer than a full batch the server accepted
                        // all it could — stop looping (remaining might be fresh pts
                        // that need duty=ON, or queue is now empty).
                        if (stored < batch.length()) return
                    }
                    code == 401 -> {
                        // Token rotated by another device login.  Evict token but
                        // keep queue — rider re-logs in on this device and drain fires
                        // again from LoginActivity.
                        Prefs.clearToken(c)
                        return
                    }
                    code == 403 -> {
                        // Plan downgraded — keep queue, retry when plan is restored.
                        Prefs.setLastSyncError(c, SyncStatus.ERR_PLAN)
                        return
                    }
                    code == 409 -> {
                        // Server says duty is off; buffered points stay for later.
                        Prefs.setLastSyncError(c, SyncStatus.ERR_DUTY_OFF)
                        return
                    }
                    // -1 or any other transient error — keep buffer, retry
                    // on next app-open, connectivity event or worker run.
                    else -> {
                        Prefs.setLastSyncError(c, SyncStatus.ERR_NET)
                        return
                    }
                }
            }
        }
    }
}
