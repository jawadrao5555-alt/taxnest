package pk.taxnest.rider

import android.content.Context
import android.content.SharedPreferences

/** Tiny SharedPreferences wrapper — single source of app state. */
object Prefs {
    private const val FILE = "rider_prefs"

    private fun sp(c: Context): SharedPreferences =
        c.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    fun token(c: Context): String? = sp(c).getString("token", null)
    fun setToken(c: Context, v: String?) = sp(c).edit().putString("token", v).apply()

    fun riderName(c: Context): String = sp(c).getString("rider_name", "") ?: ""
    fun setRiderName(c: Context, v: String) = sp(c).edit().putString("rider_name", v).apply()

    fun companyName(c: Context): String = sp(c).getString("company_name", "") ?: ""
    fun setCompanyName(c: Context, v: String) = sp(c).edit().putString("company_name", v).apply()

    fun duty(c: Context): Boolean = sp(c).getBoolean("duty", false)
    fun setDuty(c: Context, v: Boolean) = sp(c).edit().putBoolean("duty", v).apply()

    fun queueJson(c: Context): String = sp(c).getString("queue", "[]") ?: "[]"
    fun setQueueJson(c: Context, v: String) = sp(c).edit().putString("queue", v).apply()

    fun lastSync(c: Context): Long = sp(c).getLong("last_sync", 0L)
    fun setLastSync(c: Context, v: Long) = sp(c).edit().putLong("last_sync", v).apply()

    // ── Seen delivery ids (notification dedupe, v1.4.0) ───────────────────
    // Always copy into a fresh HashSet on write — mutating the set returned by
    // getStringSet is undefined behaviour in SharedPreferences.
    fun seenDeliveryIds(c: Context): Set<String> =
        sp(c).getStringSet("seen_deliveries", emptySet()) ?: emptySet()
    fun setSeenDeliveryIds(c: Context, v: Set<String>) =
        sp(c).edit().putStringSet("seen_deliveries", HashSet(v)).apply()

    // ── Pending duty-off flag ──────────────────────────────────────────────
    // Set before the /duty {on:false} network call; cleared only on success.
    // Survives process death so that an offline end-duty is reconciled with
    // the server on the next network contact (refreshMe / app-open / login).
    fun pendingDutyOff(c: Context): Boolean = sp(c).getBoolean("pending_duty_off", false)
    fun setPendingDutyOff(c: Context, v: Boolean) =
        sp(c).edit().putBoolean("pending_duty_off", v).apply()

    /**
     * Token-only eviction — used when the server returns 401 (token rotated by
     * another device login).  The GPS point queue is intentionally preserved so
     * the rider can re-login on this device and the buffered offline points will
     * drain automatically once duty resumes.
     */
    fun clearToken(c: Context) =
        sp(c).edit()
            .remove("token").remove("rider_name").remove("company_name")
            .putBoolean("duty", false)
            .apply()

    /**
     * Full session wipe — used only on VOLUNTARY logout.  Clears the queue
     * because the rider is deliberately ending the session; stale points from a
     * prior shift are noise on the next login.
     */
    fun clearSession(c: Context) =
        sp(c).edit()
            .remove("token").remove("rider_name").remove("company_name")
            .remove("seen_deliveries")
            .putBoolean("duty", false).putString("queue", "[]")
            .apply()
}
