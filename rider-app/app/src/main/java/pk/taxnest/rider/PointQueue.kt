package pk.taxnest.rider

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

/**
 * Offline-first GPS point buffer, persisted to SharedPreferences so points
 * survive process death.  Capped at 5000 points (~27 h of duty at typical
 * GPS cadence).  Oldest points are dropped first when the cap is hit.
 */
object PointQueue {
    private const val CAP = 5000
    private val lock = Any()

    fun add(c: Context, lat: Double, lng: Double, accuracyM: Int?, batteryPct: Int? = null) {
        synchronized(lock) {
            val arr = load(c)
            val p = JSONObject()
                .put("lat", lat)
                .put("lng", lng)
                .put("at", System.currentTimeMillis())
            if (accuracyM != null) p.put("acc", accuracyM)
            // v1.5.0 (Task #1106): battery % rides on each point; the server
            // denormalizes the newest live reading for the admin map. Optional
            // — old server builds simply ignore the extra key.
            if (batteryPct != null) p.put("bat", batteryPct)
            arr.put(p)
            // Cap: drop oldest.
            val trimmed = if (arr.length() > CAP) {
                JSONArray().also { out ->
                    for (i in (arr.length() - CAP) until arr.length()) out.put(arr.get(i))
                }
            } else arr
            Prefs.setQueueJson(c, trimmed.toString())
        }
    }

    fun size(c: Context): Int = synchronized(lock) { load(c).length() }

    /** Take up to [max] oldest points (they stay queued until [removeFirst]). */
    fun peekBatch(c: Context, max: Int = 100): JSONArray = synchronized(lock) {
        val arr = load(c)
        JSONArray().also { out ->
            for (i in 0 until minOf(max, arr.length())) out.put(arr.get(i))
        }
    }

    fun removeFirst(c: Context, n: Int) {
        synchronized(lock) {
            val arr = load(c)
            val out = JSONArray()
            for (i in n until arr.length()) out.put(arr.get(i))
            Prefs.setQueueJson(c, out.toString())
        }
    }

    /**
     * Acknowledge the first [n] points of an uploaded [batch] (v1.7.0).
     *
     * Why not plain [removeFirst]: the upload happens OUTSIDE the queue lock,
     * so live GPS points keep arriving meanwhile.  If the buffer is at [CAP],
     * every new point drops an old one off the front — the positions shift
     * under the uploader, and a count-based trim would then delete newer,
     * never-uploaded points.  Anchoring on the capture time of point [n]
     * removes exactly the acknowledged span, however much the front moved.
     */
    fun ackBatch(c: Context, batch: JSONArray, n: Int) {
        if (n <= 0 || batch.length() == 0) return
        val anchor = batch.optJSONObject(minOf(n, batch.length()) - 1)?.optLong("at", 0L) ?: 0L
        if (anchor > 0L) removeUpTo(c, anchor) else removeFirst(c, n)
    }

    /**
     * Drop every queued point captured at or before [atMs].  Points are
     * appended in capture order, so this is a front-trim that survives a
     * concurrent cap-trim.  Entries with no usable `at` are dropped too — the
     * server can never accept them, and keeping them would loop forever.
     */
    fun removeUpTo(c: Context, atMs: Long) {
        synchronized(lock) {
            val arr = load(c)
            val out = JSONArray()
            for (i in 0 until arr.length()) {
                val o = arr.optJSONObject(i) ?: continue
                if (o.optLong("at", 0L) > atMs) out.put(o)
            }
            Prefs.setQueueJson(c, out.toString())
        }
    }

    fun clear(c: Context) = synchronized(lock) { Prefs.setQueueJson(c, "[]") }

    private fun load(c: Context): JSONArray =
        try { JSONArray(Prefs.queueJson(c)) } catch (e: Exception) { JSONArray() }
}
