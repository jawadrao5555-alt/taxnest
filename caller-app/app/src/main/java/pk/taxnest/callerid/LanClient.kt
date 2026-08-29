package pk.taxnest.callerid

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL

/**
 * The LAN lane — the shop's own PC, reached over the shop WiFi.
 *
 * WHY THIS EXISTS
 * The whole point of the Caller ID phone is the popup on the counter. When the
 * shop's internet line drops, the phone and the counter PC are still on the
 * same WiFi one metre apart, but every ring used to die on the way to the
 * cloud. The NestPOS Desktop Agent runs a small LAN server on that PC; this
 * class is the phone's half of it.
 *
 * THE RULES THIS FILE KEEPS (see .agents/memory/pos-lan-offline-mode.md)
 *  - Cloud stays the truth. This lane is a fallback, never a second master.
 *  - Private LAN only. We refuse to send a customer's phone number in the
 *    clear to anything that is not a private address, no matter what the shop
 *    typed in. That code-level rule is what makes the manifest's cleartext
 *    permission safe.
 *  - The port is PROBED, never assumed. The agent's port is configurable, and
 *    a hardcoded 8531 would silently kill the whole lane for any shop that had
 *    to move it. The port that answered is remembered.
 *  - Short timeouts. A ring is reported from a BroadcastReceiver that Android
 *    may kill in about ten seconds; a device on the same WiFi either answers
 *    immediately or is not there.
 */
object LanClient {

    /** Agent default first, then its neighbours — same order the POS page probes. */
    private val PROBE_PORTS = listOf(8531, 8532, 8533, 8534, 8535)

    private const val CONNECT_MS = 2000
    private const val READ_MS = 3000

    /**
     * The whole LAN attempt for one ring must fit in this.
     *
     * A ring is reported from inside a detector Android does not wait on
     * forever, and the cloud lane still needs its own turn afterwards. Left
     * unbounded, one dead PC could spend a failed attempt plus a five-port
     * sweep plus a retry before the cloud even starts — and the detector would
     * be gone. So the LAN lane gets a fixed slice of wall clock and stops
     * probing when it is spent.
     */
    const val RING_BUDGET_MS = 8000L

    /** What the agent's health endpoint says it is. Anything else is not ours. */
    private const val LAN_APP = "nestpos-lan"

    data class PairResult(val ok: Boolean, val port: Int = 0, val error: String = "")

    // ── the private-address rule ────────────────────────────────────────────

    /**
     * Only a literal private IPv4 address is ever contacted.
     *
     * A hostname is refused on purpose: it could resolve anywhere, and this
     * lane sends a customer's phone number over plain http. The agent enforces
     * the mirror image of this rule on its side (it refuses any request that
     * did not come from a private address).
     */
    fun isPrivateIpv4(host: String): Boolean {
        val parts = host.trim().split(".")
        if (parts.size != 4) return false
        val n = parts.map { it.toIntOrNull() ?: return false }
        if (n.any { it < 0 || it > 255 }) return false
        return when {
            n[0] == 10 -> true
            n[0] == 192 && n[1] == 168 -> true
            n[0] == 172 && n[1] in 16..31 -> true
            // Link-local: a real peer address when the shop's router hands out
            // no lease. The PC is reachable there; the phone is not talking to
            // itself.
            n[0] == 169 && n[1] == 254 -> true
            // 127.x is deliberately NOT here. It can never be the shop's PC,
            // and allowing it would let anything else installed on this same
            // phone listen on a port and collect the LAN token plus every
            // customer's number in the clear.
            else -> false
        }
    }

    /**
     * Does this phone look like it can actually reach the internet?
     *
     * During an outage the shop WiFi is still up and connected — only the line
     * to the world is dead, and Android marks that network "not validated".
     * Asking first is what keeps a ring fast: without it every single call
     * would sit through the cloud's full timeout before trying the PC one
     * metre away, and the detector's few seconds of life would be gone.
     *
     * It is a hint, never a verdict: if the lane we pick fails, the other one
     * is still tried.
     */
    fun looksOnline(ctx: Context): Boolean = try {
        val cm = ctx.applicationContext
            .getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val caps = cm.getNetworkCapabilities(cm.activeNetwork)
        caps != null
            && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    } catch (_: Exception) {
        true // unknown = behave exactly like the old app
    }

    // ── talking to the PC ───────────────────────────────────────────────────

    private fun call(
        host: String,
        port: Int,
        method: String,
        path: String,
        body: JSONObject?,
        token: String?,
    ): Pair<Int, JSONObject?> {
        if (!isPrivateIpv4(host)) return Pair(-1, null)
        var conn: HttpURLConnection? = null
        return try {
            conn = (URL("http://$host:$port$path").openConnection() as HttpURLConnection).apply {
                requestMethod = method
                connectTimeout = CONNECT_MS
                readTimeout = READ_MS
                instanceFollowRedirects = false
                setRequestProperty("Accept", "application/json")
                setRequestProperty("Content-Type", "application/json")
                setRequestProperty(
                    "User-Agent",
                    "TaxNestCaller/" + BuildConfig.VERSION_NAME + " (" + BuildConfig.BUILD_KIND + ")"
                )
                token?.let { setRequestProperty("Authorization", "Bearer $it") }
                if (body != null) {
                    doOutput = true
                    outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
                }
            }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader()?.use(BufferedReader::readText) ?: ""
            val json = try { if (text.isNotBlank()) JSONObject(text) else null } catch (_: Exception) { null }
            Pair(code, json)
        } catch (_: Exception) {
            Pair(-1, null)
        } finally {
            conn?.disconnect()
        }
    }

    /** Is a NestPOS agent answering here? Anonymous — health leaks nothing. */
    fun isAgentAt(host: String, port: Int): Boolean {
        val (code, json) = call(host, port, "GET", "/lan/health", null, null)
        return code == 200 && json?.optString("app") == LAN_APP
    }

    /**
     * Find the port the agent is actually on: the one we remembered first, then
     * the default and its neighbours.
     */
    fun findPort(host: String, remembered: Int, deadlineAt: Long = Long.MAX_VALUE): Int? {
        val ordered = (listOf(remembered) + PROBE_PORTS).filter { it in 1..65535 }.distinct()
        for (p in ordered) {
            // Pairing passes no deadline (it runs on a screen the owner is
            // watching); a ring does, and gives up rather than outlive its
            // detector.
            if (System.currentTimeMillis() >= deadlineAt) return null
            if (isAgentAt(host, p)) return p
        }
        return null
    }

    /** Device label the owner will see in the agent window's device list. */
    fun deviceName(): String =
        (Build.MANUFACTURER + " " + Build.MODEL).trim().take(60).ifEmpty { "Caller ID phone" }

    /**
     * Pair once with the 6-digit code shown in the agent window. The token that
     * comes back is this phone's key to the PC and is kept until the owner
     * unpairs it there (or the shop clears every device).
     */
    fun pair(ctx: Context, host: String, code: String, deviceLabel: String?): PairResult {
        val cleanHost = host.trim()
        if (!isPrivateIpv4(cleanHost)) return PairResult(false, error = "not_private")

        val port = findPort(cleanHost, Prefs.lanPort(ctx)) ?: return PairResult(false, error = "no_agent")

        val body = JSONObject()
            .put("code", code.trim())
            .put("device", (deviceLabel?.trim()?.takeIf { it.isNotEmpty() } ?: deviceName()).take(60))
            .put("kind", "caller")
        val (status, json) = call(cleanHost, port, "POST", "/lan/pair", body, null)

        if (status == 200 && json?.optBoolean("ok") == true) {
            val token = json.optString("token").takeIf { it.isNotBlank() }
                ?: return PairResult(false, port, "no_token")
            Prefs.setLanHost(ctx, cleanHost)
            Prefs.setLanPort(ctx, port)
            Prefs.setLanToken(ctx, token)
            return PairResult(true, port)
        }
        return PairResult(
            false,
            port,
            when {
                status == 403 -> "bad_code"
                status == 429 -> "too_many_attempts"
                status == -1 -> "unreachable"
                else -> "failed_$status"
            },
        )
    }

    fun isPaired(ctx: Context): Boolean =
        Prefs.lanToken(ctx) != null && Prefs.lanHost(ctx)?.isNotBlank() == true

    fun forget(ctx: Context) {
        Prefs.setLanToken(ctx, null)
        Prefs.setLanHost(ctx, null)
    }

    /**
     * Push a ring to the shop PC.
     *
     * The uuid is the ring's identity and is the SAME one the cloud lane sends.
     * Without it the counter shows one call twice: once from the PC during the
     * outage, and again when the agent replays it to the cloud afterwards.
     *
     * Returns true only when the PC actually took it.
     */
    fun ring(
        ctx: Context,
        uuid: String,
        phone: String?,
        name: String?,
        source: String,
        deadlineAt: Long = System.currentTimeMillis() + RING_BUDGET_MS,
    ): Boolean {
        if (phone.isNullOrBlank()) {
            // The agent stores rings by number; a name-only ring has nothing to
            // match a customer on. The cloud lane still carries it.
            return false
        }
        val host = Prefs.lanHost(ctx)?.trim().orEmpty()
        val token = Prefs.lanToken(ctx) ?: return false
        if (!isPrivateIpv4(host)) return false

        val body = JSONObject()
            .put("uuid", uuid)
            .put("number", phone)
            .put("name", name ?: JSONObject.NULL)
            .put("source", if (source == "whatsapp") "whatsapp" else "sim")

        var port = Prefs.lanPort(ctx)
        var (status, json) = call(host, port, "POST", "/lan/caller/ring", body, token)

        // The shop moved the agent's port, or the PC was restarted onto another
        // one. Re-probe once rather than going silent for the rest of the day.
        if (status == -1 && System.currentTimeMillis() < deadlineAt) {
            val found = findPort(host, port, deadlineAt)
            if (found != null && found != port) {
                port = found
                Prefs.setLanPort(ctx, port)
                val retry = call(host, port, "POST", "/lan/caller/ring", body, token)
                status = retry.first
                json = retry.second
            }
        }

        if (status == 401) {
            // The owner unplugged this phone in the agent window. Drop the dead
            // token so the app can say "pair again" instead of failing forever.
            Prefs.setLanToken(ctx, null)
            return false
        }
        return status == 200 && json?.optBoolean("ok") == true
    }
}
