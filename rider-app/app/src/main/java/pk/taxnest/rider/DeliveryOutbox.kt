package pk.taxnest.rider

import android.content.Context
import android.content.SharedPreferences
import org.json.JSONArray
import org.json.JSONObject

/**
 * Durable offline completion outbox (Task #1508).
 *
 * Each pending confirmation is stored as a JSON object with all fields needed
 * for the POST /deliveries/{id}/delivered payload.  The outbox is persisted in
 * SharedPreferences so it survives process death.  WorkManager drains it.
 *
 * Entry schema:
 *   {
 *     "txn_id": 12345,
 *     "client_event_id": "uuid-v4",
 *     "place_type": "home"|"business"|"other",
 *     "place_label": "optional label or empty",
 *     "lat": 31.5204,
 *     "lng": 74.3587,
 *     "accuracy_m": 12,
 *     "captured_at": 1720000000000,
 *     "assignment_revision": "opaque-server-token"
 *   }
 *
 * Duplicate success is a normal 2xx response. Permanent assignment/validation
 * errors are removed; gps_not_synced and network/server errors stay for retry.
 */
object DeliveryOutbox {

    private const val FILE = "delivery_outbox"
    private const val KEY = "pending"
    private val lock = Any()

    private fun sp(c: Context): SharedPreferences =
        c.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    private fun load(c: Context): JSONArray = synchronized(lock) {
        try { JSONArray(sp(c).getString(KEY, "[]") ?: "[]") } catch (e: Exception) { JSONArray() }
    }

    private fun save(c: Context, arr: JSONArray) {
        sp(c).edit().putString(KEY, arr.toString()).apply()
    }

    /** Adds an entry. Silently replaces any existing entry with the same txn_id. */
    fun enqueue(c: Context, entry: JSONObject) {
        synchronized(lock) {
            val arr = load(c)
            val txnId = entry.optInt("txn_id", -1)
            // Remove any stale entry for the same txnId (idempotent re-try).
            val cleaned = JSONArray()
            for (i in 0 until arr.length()) {
                val e = arr.optJSONObject(i) ?: continue
                if (e.optInt("txn_id", -1) != txnId) cleaned.put(e)
            }
            cleaned.put(entry)
            save(c, cleaned)
        }
    }

    /** Returns all pending entries. */
    fun peek(c: Context): JSONArray = load(c)

    /** Removes the entry whose txn_id matches [txnId]. */
    fun remove(c: Context, txnId: Int) {
        synchronized(lock) {
            val arr = load(c)
            val out = JSONArray()
            for (i in 0 until arr.length()) {
                val e = arr.optJSONObject(i) ?: continue
                if (e.optInt("txn_id", -1) != txnId) out.put(e)
            }
            save(c, out)
        }
    }

    /** Returns the number of pending entries. */
    fun size(c: Context): Int = load(c).length()

    /** Drop confirmations that no longer match the rider's fresh /me assignments. */
    fun retainAssignments(c: Context, assignments: Set<String>) {
        synchronized(lock) {
            val arr = load(c)
            val out = JSONArray()
            for (i in 0 until arr.length()) {
                val e = arr.optJSONObject(i) ?: continue
                val identity = DeliveryAssignmentSafety.identity(
                    e.optInt("txn_id", 0).toString(),
                    e.optString("assignment_revision")
                )
                if (assignments.contains(identity)) out.put(e)
            }
            save(c, out)
        }
    }

    /** Removes an entry by client_event_id (for duplicate detection). */
    fun removeByEventId(c: Context, clientEventId: String) {
        synchronized(lock) {
            val arr = load(c)
            val out = JSONArray()
            for (i in 0 until arr.length()) {
                val e = arr.optJSONObject(i) ?: continue
                if (e.optString("client_event_id") != clientEventId) out.put(e)
            }
            save(c, out)
        }
    }
}
