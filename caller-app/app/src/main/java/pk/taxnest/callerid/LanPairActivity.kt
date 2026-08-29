package pk.taxnest.callerid

import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import kotlin.concurrent.thread

/**
 * "Shop PC" screen — pair this phone with the shop's own computer.
 *
 * WHY A SHOP WOULD USE IT
 * When the internet line dies, the cloud cannot be reached and the counter
 * stops seeing who is calling. The shop PC is on the same WiFi, one metre
 * away, and already runs the NestPOS Desktop Agent. Pair once here and rings
 * keep reaching the counter with the line down.
 *
 * WHAT THE OWNER DOES
 * Opens the agent window on the PC, turns LAN Mode on, and reads out the
 * address and the 6-digit code it shows. He types those two things here, once.
 *
 * Nothing on this screen changes the normal cloud behaviour. While the line is
 * up the app talks to the cloud exactly as before.
 *
 * `BaseActivity`, not `AppCompatActivity` — otherwise this one screen keeps
 * the old language after the picker is used.
 */
class LanPairActivity : BaseActivity() {

    private lateinit var statusText: TextView
    private lateinit var ipInput: EditText
    private lateinit var codeInput: EditText
    private lateinit var nameInput: EditText
    private lateinit var pairBtn: Button
    private lateinit var unpairBtn: Button

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_lan_pair)
        Ui.applyBarInsets(findViewById(R.id.lanRoot))
        attachLangSwitch()

        statusText = findViewById(R.id.lanStatusText)
        ipInput = findViewById(R.id.lanIpInput)
        codeInput = findViewById(R.id.lanCodeInput)
        nameInput = findViewById(R.id.lanNameInput)
        pairBtn = findViewById(R.id.lanPairBtn)
        unpairBtn = findViewById(R.id.lanUnpairBtn)

        ipInput.setText(Prefs.lanHost(this) ?: "")
        nameInput.hint = LanClient.deviceName()

        pairBtn.setOnClickListener { doPair() }
        unpairBtn.setOnClickListener {
            LanClient.forget(this)
            Toast.makeText(this, getString(R.string.lan_unpaired), Toast.LENGTH_LONG).show()
            render()
        }

        render()
    }

    override fun onResume() {
        super.onResume()
        render()
    }

    private fun render() {
        val paired = LanClient.isPaired(this)
        statusText.text = if (paired) {
            getString(R.string.lan_status_on_fmt, Prefs.lanHost(this) ?: "", Prefs.lanPort(this))
        } else {
            getString(R.string.lan_status_off)
        }
        unpairBtn.visibility = if (paired) android.view.View.VISIBLE else android.view.View.GONE
    }

    /**
     * The address may be typed as a bare IP or with the port stuck on the end,
     * because that is exactly how the agent window prints it. Both are fine —
     * a typed port becomes the first one probed, not the only one.
     */
    private fun doPair() {
        val raw = ipInput.text.toString().trim()
            .removePrefix("http://").removePrefix("https://").trimEnd('/')
        val host = raw.substringBefore(":")
        val typedPort = raw.substringAfter(":", "").toIntOrNull()
        val code = codeInput.text.toString().trim()
        val label = nameInput.text.toString().trim()

        if (!LanClient.isPrivateIpv4(host)) {
            Toast.makeText(this, getString(R.string.lan_err_not_private), Toast.LENGTH_LONG).show()
            return
        }
        if (code.length != 6 || code.any { !it.isDigit() }) {
            Toast.makeText(this, getString(R.string.lan_err_bad_code), Toast.LENGTH_LONG).show()
            return
        }

        if (typedPort != null && typedPort in 1..65535) Prefs.setLanPort(this, typedPort)

        pairBtn.isEnabled = false
        statusText.text = getString(R.string.lan_pairing)
        thread {
            val result = LanClient.pair(this, host, code, label.ifEmpty { null })
            runOnUiThread {
                pairBtn.isEnabled = true
                if (result.ok) {
                    codeInput.setText("")
                    Toast.makeText(this, getString(R.string.lan_paired), Toast.LENGTH_LONG).show()
                } else {
                    Toast.makeText(this, messageFor(result.error), Toast.LENGTH_LONG).show()
                }
                render()
            }
        }
    }

    /** Say what actually went wrong — "failed" sends an owner nowhere. */
    private fun messageFor(error: String): String = when (error) {
        "not_private" -> getString(R.string.lan_err_not_private)
        "no_agent" -> getString(R.string.lan_err_no_agent)
        "bad_code" -> getString(R.string.lan_err_bad_code)
        "too_many_attempts" -> getString(R.string.lan_err_too_many)
        "unreachable" -> getString(R.string.lan_err_no_agent)
        else -> getString(R.string.network_error)
    }
}
