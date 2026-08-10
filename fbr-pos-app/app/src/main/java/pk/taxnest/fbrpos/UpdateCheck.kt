package pk.taxnest.fbrpos

import android.app.Activity
import android.app.AlertDialog
import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.webkit.URLUtil
import android.widget.Toast
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

/**
 * Play-Store-jaisa in-app update (Task #443, Aug 2026).
 *
 * App kholte hi server se /api/app-version poochta hai; agar installed
 * versionName purana ho to "Nayi update tayyar hai" dialog + Update button.
 * Update dabane par APK khud download ho kar Android ka install prompt khul
 * jata hai (REQUEST_INSTALL_PACKAGES manifest mein) — user sirf Install dabata
 * hai, data/login mehfooz rehta hai (same keystore, install-over-old).
 *
 * FAIL-SILENT by design: net na ho, server na mile, JSON kharab ho — app
 * bilkul normal chalti rahe, koi block/toast nahi. Check ek process-launch
 * mein sirf EK dafa hota hai (rotation/re-create par dobara nahi).
 */
object UpdateCheck {

    /** Server-side app key for /api/app-version?app=… */
    private const val APP_KEY = "fbrpos"
    private const val ENDPOINT = "https://taxnest.com.pk/api/app-version?app=$APP_KEY"

    /** once-per-process guard (configChanges covers rotation, this covers re-launches of the activity in the same process) */
    @Volatile private var checked = false

    fun run(activity: Activity) {
        if (checked) return
        checked = true
        Thread {
            try {
                val conn = URL(ENDPOINT).openConnection() as HttpURLConnection
                conn.connectTimeout = 10000
                conn.readTimeout = 10000
                conn.setRequestProperty("Accept", "application/json")
                val body = conn.inputStream.bufferedReader().use { it.readText() }
                conn.disconnect()
                val json = JSONObject(body)
                if (!json.optBoolean("ok", false)) return@Thread
                val latest = json.optString("latest", "").trim()
                val apkUrl = json.optString("apk_url", "").trim()
                if (latest.isEmpty() || apkUrl.isEmpty()) return@Thread
                // Sirf first-party https APK — kabhi kisi aur host se install nahi.
                val u = Uri.parse(apkUrl)
                if (u.scheme != "https" || u.host != "taxnest.com.pk") return@Thread
                if (!isNewer(latest, BuildConfig.VERSION_NAME)) return@Thread
                activity.runOnUiThread {
                    if (activity.isFinishing || activity.isDestroyed) return@runOnUiThread
                    showDialog(activity, latest, apkUrl)
                }
            } catch (_: Exception) {
                // net nahi / server issue — app normal chalti rahe, koi shor nahi
            }
        }.start()
    }

    /** dot-numeric compare: "1.0.10" > "1.0.9"; non-numeric parts = 0 */
    private fun isNewer(latest: String, installed: String): Boolean {
        val a = latest.split('.')
        val b = installed.split('.')
        for (i in 0 until maxOf(a.size, b.size)) {
            val x = a.getOrNull(i)?.trim()?.toIntOrNull() ?: 0
            val y = b.getOrNull(i)?.trim()?.toIntOrNull() ?: 0
            if (x != y) return x > y
        }
        return false
    }

    private fun showDialog(activity: Activity, latest: String, apkUrl: String) {
        AlertDialog.Builder(activity)
            .setTitle("Nayi update tayyar hai")
            .setMessage(
                "App ka naya version v$latest aa gaya hai " +
                "(aap ke paas v${BuildConfig.VERSION_NAME} hai).\n\n" +
                "Update dabayen — APK khud download ho kar install ka option " +
                "khul jayega. Aap ka data aur login mehfooz rahega."
            )
            .setPositiveButton("Update") { _, _ -> startDownload(activity, apkUrl) }
            .setNegativeButton("Baad mein", null)
            .show()
    }

    private fun startDownload(activity: Activity, apkUrl: String) {
        try {
            val fileName = URLUtil.guessFileName(apkUrl, null, "application/vnd.android.package-archive")
            val request = DownloadManager.Request(Uri.parse(apkUrl)).apply {
                setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE)
                setMimeType("application/vnd.android.package-archive")
                if (Build.VERSION.SDK_INT >= 29) {
                    setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
                } else {
                    setDestinationInExternalFilesDir(activity, Environment.DIRECTORY_DOWNLOADS, fileName)
                }
            }
            val dm = activity.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
            val downloadId = dm.enqueue(request)
            Toast.makeText(activity, "Update download ho rahi hai…", Toast.LENGTH_LONG).show()

            // Download mukammal → seedha Android ka install prompt kholo.
            val appCtx = activity.applicationContext
            val receiver = object : BroadcastReceiver() {
                override fun onReceive(ctx: Context, intent: Intent) {
                    if (intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L) != downloadId) return
                    try { appCtx.unregisterReceiver(this) } catch (_: Exception) {}
                    try {
                        val uri = dm.getUriForDownloadedFile(downloadId) ?: return
                        val install = Intent(Intent.ACTION_VIEW).apply {
                            setDataAndType(uri, "application/vnd.android.package-archive")
                            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
                        }
                        appCtx.startActivity(install)
                    } catch (_: Exception) {
                        Toast.makeText(appCtx, "Download mukammal — notification se install karein.", Toast.LENGTH_LONG).show()
                    }
                }
            }
            val filter = IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE)
            if (Build.VERSION.SDK_INT >= 33) {
                appCtx.registerReceiver(receiver, filter, Context.RECEIVER_EXPORTED)
            } else {
                @Suppress("UnspecifiedRegisterReceiverFlag")
                appCtx.registerReceiver(receiver, filter)
            }
        } catch (_: Exception) {
            // DownloadManager na ho to browser hi khol do — wahan se install ho jayegi
            try { activity.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(apkUrl))) } catch (_: Exception) {}
        }
    }
}
