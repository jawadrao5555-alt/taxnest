package pk.taxnest.callerid

import android.app.Activity
import android.view.View
import android.widget.Button
import kotlin.concurrent.thread

/**
 * Self-update — WEBSITE builds only (`sim` + `plus`).
 *
 * Task 1346: the update code lives in `src/web/java`, which is added to the
 * `sim` and `plus` source sets ONLY. The Play build has its own no-op `Updater`
 * (`src/play/java`) and its manifest strips `REQUEST_INSTALL_PACKAGES`, because
 * Google Play's Device and Network Abuse policy does NOT list self-update among
 * the allowed uses of that permission — a Play build that downloads and installs
 * its own APK is rejected. Play Store handles updates for that build.
 *
 * Never move this file back into `src/main`: it would compile into the Play
 * bundle again and the reviewer's static scan flags it.
 *
 * `?build=sim|plus` bhejna ZAROORI hai warna plus wale phone ko clean build ka
 * APK mil jata aur WhatsApp detection chupke se khatam ho jati. Comparison
 * semver-strict hai (UpdateCheck.isNewer): server par purana version ho (ya beta
 * phone aage ho) to jhoota banner nahi aata.
 */
object Updater {

    /** true = this build may update itself (website APK). */
    const val SELF_UPDATE = true

    fun attach(activity: Activity, updateRow: View, updateBtn: Button) {
        thread {
            val (code, body) = ApiClient.get(
                "/version?build=" + BuildConfig.BUILD_KIND,
                Prefs.token(activity)
            )
            if (code !in 200..299 || body == null) return@thread
            val latest = body.optString("latest")
            val url = body.optString("apk_url")
            if (url.isBlank() || !UpdateCheck.isNewer(latest, BuildConfig.VERSION_NAME)) return@thread
            activity.runOnUiThread {
                updateRow.visibility = View.VISIBLE
                updateBtn.setOnClickListener { UpdateCheck.startDownload(activity, url) }
            }
        }
    }
}
