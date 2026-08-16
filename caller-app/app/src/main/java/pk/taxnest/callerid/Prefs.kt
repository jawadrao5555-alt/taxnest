package pk.taxnest.callerid

import android.content.Context
import android.content.SharedPreferences

/** Tiny SharedPreferences wrapper — single source of app state. */
object Prefs {
    private const val FILE = "caller_prefs"

    private fun sp(c: Context): SharedPreferences =
        c.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    fun token(c: Context): String? = sp(c).getString("token", null)
    fun setToken(c: Context, v: String?) = sp(c).edit().putString("token", v).apply()

    fun userName(c: Context): String = sp(c).getString("user_name", "") ?: ""
    fun setUserName(c: Context, v: String) = sp(c).edit().putString("user_name", v).apply()

    fun companyName(c: Context): String = sp(c).getString("company_name", "") ?: ""
    fun setCompanyName(c: Context, v: String) = sp(c).edit().putString("company_name", v).apply()

    fun featureEnabled(c: Context): Boolean = sp(c).getBoolean("feature_enabled", false)
    fun setFeatureEnabled(c: Context, v: Boolean) = sp(c).edit().putBoolean("feature_enabled", v).apply()

    fun lastSentAt(c: Context): Long = sp(c).getLong("last_sent_at", 0L)
    fun setLastSentAt(c: Context, v: Long) = sp(c).edit().putLong("last_sent_at", v).apply()
}
