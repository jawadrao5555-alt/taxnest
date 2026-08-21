package pk.taxnest.callerid

import android.content.Intent
import android.os.Bundle
import android.text.InputType
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.ImageView
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import org.json.JSONObject
import kotlin.concurrent.thread

/**
 * Portal-credentials sign-in (rider-app template). Sirf shop ka admin/manager
 * login chalta hai — server side isPosAdmin() check karta hai.
 */
class LoginActivity : AppCompatActivity() {

    private var passwordVisible = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Prefs.token(this) != null) { goMain(); return }
        setContentView(R.layout.activity_login)
        Ui.applyBarInsets(findViewById(R.id.loginRoot))

        val email = findViewById<EditText>(R.id.email)
        val password = findViewById<EditText>(R.id.password)
        val toggle = findViewById<ImageView>(R.id.togglePassword)
        val error = findViewById<TextView>(R.id.errorText)
        val btn = findViewById<Button>(R.id.loginBtn)

        toggle.setOnClickListener {
            passwordVisible = !passwordVisible
            password.inputType = if (passwordVisible)
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD
            else
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            toggle.setImageResource(if (passwordVisible) R.drawable.ic_eye_off else R.drawable.ic_eye)
            password.setSelection(password.text.length)
        }

        btn.setOnClickListener {
            val e = email.text.toString().trim()
            val p = password.text.toString()
            if (e.isEmpty() || p.isEmpty()) {
                error.text = getString(R.string.fill_both)
                error.visibility = View.VISIBLE
                return@setOnClickListener
            }
            btn.isEnabled = false
            error.visibility = View.GONE

            thread {
                // device string mein build kind bhi (Task 1345/1346) — POS →
                // Customize par dikhta hai, support ko foran pata chal jata hai
                // ke us phone par kaunsi build hai: clean (sirf SIM), WhatsApp
                // (website), ya Play Store wali.
                val kind = when (BuildConfig.BUILD_KIND) {
                    "plus" -> " · WhatsApp build"
                    "play" -> " · Play build"
                    else -> " · clean build"
                }
                val device = (android.os.Build.MANUFACTURER + " " + android.os.Build.MODEL).trim() + kind
                val (code, body) = ApiClient.post(
                    "/login",
                    JSONObject().put("email", e).put("password", p).put("device", device.take(120))
                )
                runOnUiThread {
                    btn.isEnabled = true
                    when {
                        code in 200..299 && body?.optBoolean("ok") == true -> {
                            Prefs.setToken(this, body.optString("token"))
                            Prefs.setUserName(this, body.optString("user"))
                            Prefs.setCompanyName(this, body.optString("company"))
                            Prefs.setFeatureEnabled(this, body.optBoolean("enabled", false))
                            goMain()
                        }
                        code == -1 -> {
                            error.text = getString(R.string.network_error)
                            error.visibility = View.VISIBLE
                        }
                        else -> {
                            error.text = body?.optString("message")?.takeIf { it.isNotBlank() }
                                ?: getString(R.string.login_failed)
                            error.visibility = View.VISIBLE
                        }
                    }
                }
            }
        }
    }

    private fun goMain() {
        startActivity(Intent(this, MainActivity::class.java))
        finish()
    }
}
