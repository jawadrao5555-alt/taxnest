package pk.taxnest.callerid

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.widget.Button
import android.widget.TextView

/**
 * Prominent disclosure (Task 1346) — shown BEFORE the Android notification-access
 * screen in every build that reads notifications (`plus` + `play`).
 *
 * Google Play's User Data policy requires an in-app disclosure that:
 *   - appears in the normal usage flow, not only in the privacy policy,
 *   - says what data is accessed, why, and where it goes,
 *   - and takes an affirmative action (this screen's agree button) before the
 *     permission request. Declining must be possible and must not break the app.
 *
 * Reject-proof rule: NEVER call Detector.openSettings() from anywhere else.
 *
 * Task 1382 — `BaseActivity` se aati hai, is liye poori disclosure user ki
 * chuni hui zubaan (English / Roman Urdu / Urdu) mein aati hai. Teenon
 * versions bilkul ek hi baat kahte hain; kisi ek ko chhota karna Play ki User
 * Data policy tor dega.
 */
class NotificationDisclosureActivity : BaseActivity() {

    companion object {
        const val PRIVACY_URL = "https://taxnest.com.pk/privacy"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_notification_disclosure)
        Ui.applyBarInsets(findViewById(R.id.disclosureRoot))

        findViewById<TextView>(R.id.privacyLink).setOnClickListener {
            try {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(PRIVACY_URL)))
            } catch (_: Exception) {}
        }

        findViewById<Button>(R.id.agreeBtn).setOnClickListener {
            Prefs.setNotifDisclosureAccepted(this, true)
            // Access pehle se ON ho (user ne seedha Android Settings se di ho, ya
            // purani build se update aayi ho) to sirf consent record karke wapas —
            // settings dobara kholne ki zaroorat nahi.
            if (!Detector.listenerEnabled(this)) Detector.openSettings(this)
            finish()
        }

        findViewById<Button>(R.id.declineBtn).setOnClickListener { finish() }
    }
}
