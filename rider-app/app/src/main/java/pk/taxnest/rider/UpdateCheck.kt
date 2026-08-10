package pk.taxnest.rider

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
 * Play-Store-jaisa APK download + install helper (Task #443, Aug 2026).
 *
 * Rider app apni PURANI update-row flow rakhta hai (MainActivity.checkUpdate →
 * /api/rider-app/v1/version, jo ab sirf rider_app_latest_version SystemSetting
 * parhta hai — empty = koi update prompt nahi). Yeh object sirf Update button
 * ka kaam badalta hai: browser kholne ke bajaye APK khud download ho kar
 * Android ka install prompt khulta hai (REQUEST_INSTALL_PACKAGES manifest
 * mein) — user sirf Install dabata hai, data/login mehfooz rehta hai.
 */
object UpdateCheck {

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
