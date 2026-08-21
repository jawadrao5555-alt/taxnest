package pk.taxnest.rider

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import android.os.PowerManager
import androidx.core.content.ContextCompat

/**
 * Sync health, in one place (v1.7.0, Task #1359).
 *
 * The rider must be able to see — on the home screen AND on the duty
 * notification, without opening anything — whether his location is actually
 * reaching the shop, and if not, WHY.  Both surfaces render from here so they
 * can never disagree.
 *
 * "Late" is deliberately generous (LATE_MS) compared to the server's silent
 * threshold: the rider should only be alarmed when something is really wrong,
 * not on a normal 45 s flush gap or a one-off failed upload.
 */
object SyncStatus {

    /** Failure codes stored in Prefs.lastSyncError. */
    const val ERR_NONE = ""
    const val ERR_NET = "net"          // upload attempt failed / no connectivity
    const val ERR_PERM = "perm"        // location permission missing or GPS off
    const val ERR_PLAN = "plan"        // shop package no longer allows tracking
    const val ERR_DUTY_OFF = "duty_off" // server says duty is off

    /** No successful upload for this long while on duty = warn the rider. */
    const val LATE_MS = 6 * 60_000L

    /** ms since the last successful upload, or null when there never was one. */
    fun ageMs(c: Context): Long? {
        val last = Prefs.lastSync(c)
        if (last <= 0L) return null
        val age = System.currentTimeMillis() - last
        return if (age < 0) 0L else age
    }

    /**
     * True when the rider should see a red warning: duty is ON and nothing has
     * reached the server for LATE_MS.  A fresh duty session gets the same
     * window as grace (his last sync may be hours old from the previous shift).
     */
    fun isLate(c: Context): Boolean {
        if (!Prefs.duty(c)) return false
        val dutyAge = System.currentTimeMillis() - Prefs.dutyStartedAt(c)
        if (Prefs.dutyStartedAt(c) > 0L && dutyAge < LATE_MS) return false
        val age = ageMs(c) ?: return true // on duty, never synced → late
        return age > LATE_MS
    }

    /** "ابھی" / "5 منٹ پہلے" / "2 گھنٹے پہلے" / "ابھی نہیں". */
    fun lastSyncLabel(c: Context): String {
        val age = ageMs(c) ?: return c.getString(R.string.never)
        val mins = age / 60_000L
        return when {
            mins < 1 -> c.getString(R.string.ago_just_now)
            mins < 60 -> c.getString(R.string.ago_minutes, mins.toInt())
            else -> c.getString(R.string.ago_hours, (mins / 60).toInt())
        }
    }

    /**
     * Best-guess reason the uploads stopped, in the rider's words.  Checked in
     * the order he can actually act on: permission → net → battery → generic.
     */
    fun reasonText(c: Context): String {
        if (!hasLocationPermission(c)) return c.getString(R.string.reason_perm)
        if (!hasNetwork(c)) return c.getString(R.string.reason_net)
        if (Prefs.lastSyncError(c) == ERR_PERM) return c.getString(R.string.reason_perm)
        if (Prefs.lastSyncError(c) == ERR_NET) return c.getString(R.string.reason_net)
        if (Prefs.lastSyncError(c) == ERR_PLAN) return c.getString(R.string.plan_locked)
        if (!isBatteryUnrestricted(c)) return c.getString(R.string.reason_battery)
        return c.getString(R.string.reason_unknown)
    }

    fun hasLocationPermission(c: Context): Boolean =
        ContextCompat.checkSelfPermission(c, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
        ContextCompat.checkSelfPermission(c, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED

    fun hasNetwork(c: Context): Boolean = try {
        val cm = c.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
        val net = cm?.activeNetwork
        val caps = net?.let { cm.getNetworkCapabilities(it) }
        caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) == true
    } catch (e: Exception) {
        true // unknown → don't blame the network
    }

    /** False = the phone may freeze the app in the background (Doze / OEM saver). */
    fun isBatteryUnrestricted(c: Context): Boolean = try {
        if (Build.VERSION.SDK_INT < 23) true
        else (c.getSystemService(Context.POWER_SERVICE) as PowerManager)
            .isIgnoringBatteryOptimizations(c.packageName)
    } catch (e: Exception) {
        true
    }
}
