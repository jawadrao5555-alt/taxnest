package pk.taxnest.callerid

import org.json.JSONObject
import java.io.BufferedReader
import java.net.HttpURLConnection
import java.net.URL

/**
 * Zero-dependency HTTP client for the TaxNest caller-app API.
 * All calls are blocking — run them off the main thread.
 * Returns (httpCode, body) — code -1 means network failure.
 */
object ApiClient {
    const val BASE = "https://taxnest.com.pk/api/caller-app/v1"

    fun post(path: String, body: JSONObject, token: String? = null): Pair<Int, JSONObject?> =
        request("POST", path, body, token)

    fun get(path: String, token: String? = null): Pair<Int, JSONObject?> =
        request("GET", path, null, token)

    private fun request(method: String, path: String, body: JSONObject?, token: String?): Pair<Int, JSONObject?> {
        var conn: HttpURLConnection? = null
        return try {
            conn = (URL(BASE + path).openConnection() as HttpURLConnection).apply {
                requestMethod = method
                connectTimeout = 15000
                readTimeout = 20000
                setRequestProperty("Accept", "application/json")
                setRequestProperty("Content-Type", "application/json")
                setRequestProperty("User-Agent", "TaxNestCaller/" + BuildConfig.VERSION_NAME)
                token?.let { setRequestProperty("Authorization", "Bearer $it") }
                if (body != null) {
                    doOutput = true
                    outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }
                }
            }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader()?.use(BufferedReader::readText) ?: ""
            val json = try { if (text.isNotBlank()) JSONObject(text) else null } catch (e: Exception) { null }
            Pair(code, json)
        } catch (e: Exception) {
            Pair(-1, null)
        } finally {
            conn?.disconnect()
        }
    }
}
