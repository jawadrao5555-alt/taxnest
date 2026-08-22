package pk.taxnest.rider

import android.content.Context
import android.content.SharedPreferences
import org.json.JSONArray

/**
 * Lightweight cache of the current open deliveries (Task #1508).
 *
 * TrackingService needs the destination coordinates for each delivery to fire
 * arrival notifications, but it cannot call /me itself (that's MainActivity's
 * job).  The cache is written whenever MainActivity receives a fresh /me
 * payload and read by TrackingService on every GPS callback.
 *
 * Only the fields needed for arrival detection are stored:
 *   id, invoice_number, destination_lat, destination_lng,
 *   arrival_radius_m, assignment_revision, status
 *
 * The cache is stored in a separate SharedPreferences file so it does not
 * interfere with the main rider_prefs file (no lock contention).
 */
object DeliveryArrivalCache {

    private const val FILE = "delivery_cache"
    private const val KEY = "deliveries"

    private fun sp(c: Context): SharedPreferences =
        c.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    /** Overwrites the cache with the full deliveries JSONArray from /me. */
    fun set(c: Context, arr: JSONArray) {
        sp(c).edit().putString(KEY, arr.toString()).apply()
    }

    /** Returns the cached deliveries (empty array if none or parse error). */
    fun get(c: Context): JSONArray =
        try { JSONArray(sp(c).getString(KEY, "[]") ?: "[]") } catch (e: Exception) { JSONArray() }

    /** Clears the cache (on logout / session expiry). */
    fun clear(c: Context) {
        sp(c).edit().remove(KEY).apply()
    }
}
