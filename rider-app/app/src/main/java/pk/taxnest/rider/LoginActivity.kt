package pk.taxnest.rider

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import org.json.JSONObject
import kotlin.concurrent.thread

class LoginActivity : AppCompatActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Prefs.token(this) != null) {
            goMain(); return
        }
        setContentView(R.layout.activity_login)

        val email = findViewById<EditText>(R.id.email)
        val password = findViewById<EditText>(R.id.password)
        val btn = findViewById<Button>(R.id.loginBtn)
        val error = findViewById<TextView>(R.id.errorText)

        btn.setOnClickListener {
            val e = email.text.toString().trim()
            val p = password.text.toString()
            if (e.isEmpty() || p.isEmpty()) return@setOnClickListener
            btn.isEnabled = false
            error.visibility = View.GONE

            thread {
                val (code, body) = ApiClient.post(
                    "/login", JSONObject().put("email", e).put("password", p)
                )
                runOnUiThread {
                    btn.isEnabled = true
                    when {
                        code in 200..299 && body?.optBoolean("ok") == true -> {
                            Prefs.setToken(this, body.optString("token"))
                            val rider = body.optJSONObject("rider")
                            Prefs.setRiderName(this, rider?.optString("name") ?: "")
                            Prefs.setCompanyName(this, rider?.optString("company") ?: "")
                            Prefs.setDuty(this, body.optBoolean("duty", false))
                            // Drain any buffered offline points from previous duty
                            // sessions.  Fires even if duty is now OFF so points
                            // captured before a 401 eviction are not stranded.
                            QueueDrain.drainAsync(this)
                            // v1.4.0: start the 15-min background delivery check.
                            DeliveryCheckWorker.schedule(this)
                            goMain()
                        }
                        code == 403 && body?.optString("error") == "plan_locked" -> {
                            error.text = body.optString("message", getString(R.string.plan_locked))
                            error.visibility = View.VISIBLE
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
