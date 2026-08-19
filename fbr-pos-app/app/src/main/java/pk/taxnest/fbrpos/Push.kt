package pk.taxnest.fbrpos

import android.content.Context
import android.net.Uri
import android.webkit.CookieManager
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

/**
 * FCM token plumbing for the FBR POS shell (Task #1283) — instant fail-queue
 * alerts and day-close reminders for the shop owner/cashier. Straight port of
 * the PRA shell's Push.kt (pos-app, Task #1142) with the FBR endpoints:
 * register = /fbr-pos/app/fcm-token (header X-TaxNest-App: fbrpos), clear =
 * /api/fbr-pos-app/fcm-token/clear.
 *
 * Mirrors the rider app's degradation contract: EVERY call is wrapped so the
 * whole feature is a silent no-op when Firebase is unavailable (build without
 * google-services.json → FirebaseApp never initializes → getInstance()
 * throws, caught here; phone without Play Services → token fetch just fails).
 * The web app keeps working — push is additive, never a dependency.
 *
 * No bearer token: the shell rides the WebView's session cookie
 * (CookieManager) for registration, plus the X-TaxNest-App header the server
 * requires as its cross-site-forgery guard. Logout-time clear is stateless
 * (authenticated by token possession) because the session cookie is already
 * dead when the shell sees /fbr-pos/login again.
 */
object Push {

    private const val PREFS = "tn_push"
    private const val KEY_LOGGED_IN = "logged_in"
    private const val KEY_LAST_TOKEN = "last_token"
    private val REGISTER_URL = "https://" + MainActivity.BASE_HOST + "/fbr-pos/app/fcm-token"
    private val CLEAR_URL = "https://" + MainActivity.BASE_HOST + "/api/fbr-pos-app/fcm-token/clear"

    /**
     * Called from onPageStarted for every main-frame navigation. Login is
     * detected by landing on a first-party /fbr-pos/ page PAST the guest
     * pages; logout by landing back on /fbr-pos/login while we thought we
     * were in.
     */
    fun onNavigation(c: Context, url: String) {
        try {
            val uri = Uri.parse(url)
            val host = uri.host ?: return
            if (host != MainActivity.BASE_HOST && !host.endsWith(".${MainActivity.BASE_HOST}")) return
            val path = uri.path ?: return
            val guest = path == "/fbr-pos" || path == "/fbr-pos/" ||
                path.startsWith("/fbr-pos/login") || path.startsWith("/fbr-pos/register") ||
                path.startsWith("/fbr-pos/invoice/share") // public receipt page — no session
            if (path.startsWith("/fbr-pos/") && !guest) {
                onLoggedIn(c)
            } else if (path.startsWith("/fbr-pos/login") && isLoggedIn(c)) {
                onLoggedOut(c)
            }
        } catch (e: Exception) {
            // navigation tracking must never crash the shell
        }
    }

    fun isLoggedIn(c: Context): Boolean =
        c.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean(KEY_LOGGED_IN, false)

    /** Session detected → fetch the FCM token and register it (idempotent). */
    private fun onLoggedIn(c: Context) {
        val p = c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val wasIn = p.getBoolean(KEY_LOGGED_IN, false)
        if (!wasIn) p.edit().putBoolean(KEY_LOGGED_IN, true).apply()
        // Re-register on every fresh login (user may have switched accounts —
        // the server moves the device row to the new user); skip when already
        // registered this login session.
        if (!wasIn || p.getString(KEY_LAST_TOKEN, null) == null) {
            register(c)
        }
    }

    /** Back on the login page → stop this device's pushes. */
    private fun onLoggedOut(c: Context) {
        val p = c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val last = p.getString(KEY_LAST_TOKEN, null)
        p.edit().putBoolean(KEY_LOGGED_IN, false).remove(KEY_LAST_TOKEN).apply()
        thread(name = "fcm-clear") {
            // Server-side clear by token possession (session already dead).
            if (!last.isNullOrBlank()) {
                try {
                    postJson(CLEAR_URL, JSONObject().put("token", last), withSession = false)
                } catch (e: Exception) { /* dead token also self-cleans on next send */ }
            }
            // Local invalidation: even if the server call raced, a deleted
            // token means FCM answers UNREGISTERED and the row gets purged.
            try {
                com.google.firebase.messaging.FirebaseMessaging.getInstance().deleteToken()
            } catch (e: Exception) { /* not configured — nothing registered anyway */ }
        }
    }

    /** Fetch the current FCM token and upload it. */
    fun register(c: Context) {
        try {
            com.google.firebase.messaging.FirebaseMessaging.getInstance().token
                .addOnSuccessListener { t -> if (!t.isNullOrBlank()) upload(c, t) }
            // No failure listener needed: web polling covers, next launch retries.
        } catch (e: Exception) {
            // Firebase not configured on this build — dormant by design.
        }
    }

    /**
     * POST the token to the server with the WebView session cookie (also
     * called by PushService.onNewToken on rotation). Skipped while logged
     * out — login re-registers anyway.
     */
    fun upload(c: Context, fcmToken: String) {
        if (!isLoggedIn(c)) return
        thread(name = "fcm-register") {
            try {
                val body = JSONObject()
                    .put("token", fcmToken)
                    .put("app_version", BuildConfig.VERSION_NAME)
                val code = postJson(REGISTER_URL, body, withSession = true)
                if (code in 200..299) {
                    c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
                        .edit().putString(KEY_LAST_TOKEN, fcmToken).apply()
                }
                // Non-2xx (session raced out, throttle): self-heals — next
                // login or token rotation re-registers.
            } catch (e: Exception) {
                // network failure — same self-heal
            }
        }
    }

    /** Minimal JSON POST; returns HTTP status or throws. Never used on UI thread. */
    private fun postJson(url: String, body: JSONObject, withSession: Boolean): Int {
        val conn = URL(url).openConnection() as HttpURLConnection
        return try {
            conn.requestMethod = "POST"
            conn.connectTimeout = 10000
            conn.readTimeout = 10000
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json")
            conn.setRequestProperty("Accept", "application/json")
            // Server's CSRF stand-in — only the FBR shell sets this value.
            conn.setRequestProperty("X-TaxNest-App", "fbrpos")
            if (withSession) {
                CookieManager.getInstance().getCookie("https://${MainActivity.BASE_HOST}")
                    ?.let { conn.setRequestProperty("Cookie", it) }
            }
            conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
            conn.responseCode
        } finally {
            conn.disconnect()
        }
    }
}
