package pk.taxnest.waiter

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import org.json.JSONObject
import java.net.InetAddress
import java.net.URL
import java.security.MessageDigest
import java.security.SecureRandom
import java.security.cert.X509Certificate
import javax.net.ssl.HttpsURLConnection
import javax.net.ssl.SSLContext
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

data class PairingPayload(
    val url: String,
    val spkiPin: String,
    val certPin: String,
    val code: String,
    val nonce: String,
    val waiterLease: String,
    val expiresAt: Long
)

object LocalCoreClient {
    private val waiterCommands = setOf(
        "order.hold", "order.claim", "table.claim", "table.release", "table.shift"
    )
    fun isCommandAllowed(role: String, permissions: Set<String>, type: String): Boolean =
        role == "waiter" && (type in waiterCommands ||
            (type in setOf("order.cancel", "order.settle") && type in permissions))
    fun isCredentialBindingValid(storedUser: String, presentedUser: String, role: String, revoked: Boolean): Boolean =
        storedUser.isNotBlank() && storedUser == presentedUser && role == "waiter" && !revoked

    fun parsePayload(raw: String, now: Long = System.currentTimeMillis()): PairingPayload {
        val json = JSONObject(raw)
        require(json.optInt("v") == 1) { "Unsupported pairing payload" }
        val url = URL(json.getString("url"))
        require(url.protocol == "https" && url.path.removeSuffix("/").isEmpty()) { "HTTPS Local Core URL required" }
        require(isPrivateLiteral(url.host)) { "Local Core must use a private LAN address" }
        val code = json.getString("code")
        require(code.matches(Regex("[0-9]{6}"))) { "Invalid pairing code" }
        val expiry = json.getLong("expires_at")
        require(expiry > now && expiry <= now + 10 * 60 * 1000) { "Pairing payload expired" }
        val spki = json.getString("spki_sha256")
        val cert = json.getString("cert_sha256")
        require(spki.isNotBlank() && cert.isNotBlank() && json.getString("nonce").isNotBlank()) { "Pairing pin missing" }
        val waiterLease = json.getString("waiter_lease")
        require(waiterLease.length >= 16) { "Authenticated waiter session is missing" }
        return PairingPayload(url.toString().removeSuffix("/"), spki, cert, code,
            json.getString("nonce"), waiterLease, expiry)
    }

    private fun isPrivateLiteral(host: String): Boolean {
        if (!host.matches(Regex("[0-9.]+"))) return false
        val b = try { InetAddress.getByName(host).address.map { it.toInt() and 255 } } catch (_: Exception) { return false }
        return b.size == 4 && (b[0] == 10 || b[0] == 127 ||
            (b[0] == 192 && b[1] == 168) || (b[0] == 172 && b[1] in 16..31) ||
            (b[0] == 169 && b[1] == 254))
    }

    private fun connection(payload: PairingPayload, path: String): HttpsURLConnection {
        val trust = object : X509TrustManager {
            override fun getAcceptedIssuers(): Array<X509Certificate> = emptyArray()
            override fun checkClientTrusted(chain: Array<X509Certificate>, authType: String) =
                throw java.security.cert.CertificateException("Client certificates are not accepted")
            override fun checkServerTrusted(chain: Array<X509Certificate>, authType: String) {
                if (chain.size != 1) throw java.security.cert.CertificateException("Unexpected certificate chain")
                val cert = chain[0]
                cert.checkValidity()
                val spki = android.util.Base64.encodeToString(
                    MessageDigest.getInstance("SHA-256").digest(cert.publicKey.encoded),
                    android.util.Base64.NO_WRAP
                )
                val fingerprint = android.util.Base64.encodeToString(
                    MessageDigest.getInstance("SHA-256").digest(cert.encoded),
                    android.util.Base64.NO_WRAP
                )
                if (!MessageDigest.isEqual(spki.toByteArray(), payload.spkiPin.toByteArray()) ||
                    !MessageDigest.isEqual(fingerprint.toByteArray(), payload.certPin.toByteArray())) {
                    throw java.security.cert.CertificateException("Local Core TLS pin mismatch")
                }
            }
        }
        val ssl = SSLContext.getInstance("TLS")
        ssl.init(null, arrayOf<TrustManager>(trust), SecureRandom())
        return (URL(payload.url + path).openConnection() as HttpsURLConnection).apply {
            sslSocketFactory = ssl.socketFactory
            hostnameVerifier = javax.net.ssl.HostnameVerifier { _, session ->
                try {
                    trust.checkServerTrusted(arrayOf(session.peerCertificates[0] as X509Certificate), "RSA")
                    true
                } catch (_: Exception) { false }
            }
            connectTimeout = 7000
            readTimeout = 10000
            instanceFollowRedirects = false
        }
    }

    fun pair(context: Context, payload: PairingPayload, deviceName: String) {
        require(payload.expiresAt > System.currentTimeMillis()) { "Pairing payload expired" }
        val conn = connection(payload, "/pair")
        conn.requestMethod = "POST"
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "application/json")
        conn.outputStream.use {
            it.write(JSONObject().put("code", payload.code).put("nonce", payload.nonce)
                .put("device", deviceName.take(60)).put("waiter_lease", payload.waiterLease).toString().toByteArray())
        }
        val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
        val response = JSONObject(stream.bufferedReader().use { it.readText() })
        require(conn.responseCode == 200 && response.optBoolean("ok")) {
            "Pairing refused: ${response.optString("error", conn.responseCode.toString())}"
        }
        val token = response.getString("device_token")
        require(token.length >= 32) { "Invalid device credential" }
        val key = MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build()
        EncryptedSharedPreferences.create(
            context, "local_core_credentials", key,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        ).edit().putString("url", payload.url).putString("spki_pin", payload.spkiPin)
            .putString("cert_pin", payload.certPin).putString("device_token", token)
            .putString("user_id", response.getString("user_id"))
            .putString("role", response.getString("role"))
            .putBoolean("revoked", false)
            .putStringSet("permissions", buildSet {
                val values = response.getJSONArray("permissions")
                for (i in 0 until values.length()) add(values.getString(i))
            }).apply()
    }

    /**
     * Native Local Core calls never use WebView trust or Android's permissive
     * SSL-error callback. The saved exact pins and rotating device credential
     * are applied together for every command/query.
     */
    fun request(context: Context, path: String, request: JSONObject): JSONObject {
        require(path == "/command" || path == "/query") { "Sensitive Local Core endpoint required" }
        val key = MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build()
        val prefs = EncryptedSharedPreferences.create(
            context, "local_core_credentials", key,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
        val url = prefs.getString("url", null) ?: error("Local Core is not paired")
        val spki = prefs.getString("spki_pin", null) ?: error("Local Core pin is missing")
        val cert = prefs.getString("cert_pin", null) ?: error("Local Core pin is missing")
        val token = prefs.getString("device_token", null) ?: error("Device credential is missing")
        require(isCredentialBindingValid(
            prefs.getString("user_id", "").orEmpty(),
            prefs.getString("user_id", "").orEmpty(),
            prefs.getString("role", "").orEmpty(),
            prefs.getBoolean("revoked", false)
        )) { "Waiter credential is revoked or invalid" }
        if (path == "/command") {
            val type = request.optJSONObject("command")?.optString("type").orEmpty()
            val role = prefs.getString("role", "").orEmpty()
            val permissions = prefs.getStringSet("permissions", emptySet()) ?: emptySet()
            require(isCommandAllowed(role, permissions, type)) { "Waiter permission denies $type" }
        }
        val pinned = PairingPayload(url, spki, cert, "000000", "saved", "saved-waiter-lease", Long.MAX_VALUE)
        val conn = connection(pinned, path)
        conn.requestMethod = "POST"
        conn.doOutput = true
        conn.setRequestProperty("Content-Type", "application/json")
        conn.setRequestProperty("Authorization", "Bearer $token")
        conn.outputStream.use { it.write(request.toString().toByteArray()) }
        val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
        val response = JSONObject(stream.bufferedReader().use { it.readText() })
        if (conn.responseCode == 401 &&
            response.optString("error") in setOf("waiter_session_revoked", "device_credential_required")) {
            prefs.edit().putBoolean("revoked", true).remove("device_token").commit()
        }
        require(conn.responseCode in 200..299 && response.optBoolean("ok")) {
            "Local Core refused request: ${response.optString("error", conn.responseCode.toString())}"
        }
        response.optString("next_device_token").takeIf { it.length >= 32 }?.let {
            prefs.edit().putString("device_token", it).commit()
        }
        return response
    }
}