package pk.taxnest.callerid

import android.app.Activity
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

/**
 * Play-Store-jaisa APK download + install helper (rider-app template).
 * MainActivity /version se latest version parhta hai; Update button dabane par
 * APK download ho kar Android ka install prompt khulta hai.
 */
object UpdateCheck {

    /**
     * Semver-strict compare (rider-app rule): banner SIRF tab jab server ka
     * version installed se ZYADA ho. Barabar/purana = koi banner —  warna ek
     * beta phone (jo server se aage hai) hamesha "update" maangta rehta hai.
     */
    fun isNewer(latest: String?, current: String?): Boolean {
        val a = parts(latest) ?: return false
        val b = parts(current) ?: return false
        for (i in 0 until maxOf(a.size, b.size)) {
            val x = a.getOrElse(i) { 0 }
            val y = b.getOrElse(i) { 0 }
            if (x != y) return x > y
        }
        return false
    }

    private fun parts(v: String?): List<Int>? {
        val s = v?.trim().orEmpty()
        if (s.isEmpty() || !Regex("^\\d+(\\.\\d+)*$").matches(s)) return null
        return s.split(".").map { it.toIntOrNull() ?: 0 }
    }

    fun startDownload(activity: Activity, apkUrl: String) {
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
            try { activity.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(apkUrl))) } catch (_: Exception) {}
        }
    }
}
