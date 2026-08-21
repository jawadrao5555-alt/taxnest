package pk.taxnest.rider

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings

/**
 * OEM autostart / background-run settings shortcut (v1.7.0, Task #1359).
 *
 * Battery-optimisation exemption (the standard Android switch) is not enough
 * on the phones our riders actually carry: Infinix/Tecno (Transsion), Xiaomi,
 * Oppo, Vivo, Huawei and Samsung all ship a SECOND, vendor-only list that
 * decides whether an app may run in the background at all.  There is no public
 * API for it, so we probe a table of known component names and open the first
 * one this phone actually has.
 *
 * Everything is best-effort: if nothing resolves we fall back to the app's own
 * system settings page, which every phone has.
 */
object AutoStartHelper {

    // package → activity, most specific first. Unresolvable entries are skipped.
    private val CANDIDATES = listOf(
        // Transsion (Infinix / Tecno / itel) — the most common rider phones here
        "com.transsion.phonemaster" to "com.cyin.himgr.autostart.AutoStartActivity",
        "com.transsion.phonemanager" to "com.cyin.himgr.autostart.AutoStartActivity",
        // Xiaomi / Redmi / POCO
        "com.miui.securitycenter" to "com.miui.permcenter.autostart.AutoStartManagementActivity",
        // Oppo / Realme
        "com.coloros.safecenter" to "com.coloros.safecenter.permission.startup.StartupAppListActivity",
        "com.coloros.safecenter" to "com.coloros.safecenter.startupapp.StartupAppListActivity",
        "com.oppo.safe" to "com.oppo.safe.permission.startup.StartupAppListActivity",
        // Vivo / iQOO
        "com.vivo.permissionmanager" to "com.vivo.permissionmanager.activity.BgStartUpManagerActivity",
        "com.iqoo.secure" to "com.iqoo.secure.ui.phoneoptimize.AddWhiteListActivity",
        // Huawei / Honor
        "com.huawei.systemmanager" to "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity",
        "com.huawei.systemmanager" to "com.huawei.systemmanager.optimize.process.ProtectActivity",
        // Asus
        "com.asus.mobilemanager" to "com.asus.mobilemanager.entry.FunctionActivity",
        // Letv
        "com.letv.android.letvsafe" to "com.letv.android.letvsafe.AutobootManageActivity",
        // Samsung (device care battery list)
        "com.samsung.android.lool" to "com.samsung.android.sm.ui.battery.BatteryActivity"
    )

    /** True when this phone has a vendor autostart screen we can open. */
    fun isSupported(c: Context): Boolean = resolve(c) != null

    /**
     * Open the vendor autostart screen, or the app's system settings page as a
     * fallback.  @return true when something opened.
     */
    fun open(c: Context): Boolean {
        val intent = resolve(c) ?: appSettingsIntent(c)
        return try {
            c.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            true
        } catch (e: Exception) {
            try {
                c.startActivity(appSettingsIntent(c).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                true
            } catch (e2: Exception) {
                false
            }
        }
    }

    private fun resolve(c: Context): Intent? {
        for ((pkg, cls) in CANDIDATES) {
            val intent = Intent().setComponent(ComponentName(pkg, cls))
            try {
                if (c.packageManager.resolveActivity(intent, 0) != null) return intent
            } catch (e: Exception) {}
        }
        return null
    }

    private fun appSettingsIntent(c: Context): Intent = Intent(
        Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
        Uri.fromParts("package", c.packageName, null)
    )
}
