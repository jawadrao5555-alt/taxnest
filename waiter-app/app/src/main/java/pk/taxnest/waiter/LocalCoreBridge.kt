package pk.taxnest.waiter

import android.content.Context
import android.webkit.JavascriptInterface
import org.json.JSONObject

/**
 * Narrow bridge used by the first-party waiter page. Credentials remain in
 * Android encrypted storage; JavaScript can neither read them nor choose a
 * destination, disable pinning, or send a cleartext request.
 */
class LocalCoreBridge(private val context: Context, private val topUrl: () -> String?) {
    @JavascriptInterface
    fun command(json: String): String = call("/command", "command", json)

    @JavascriptInterface
    fun query(json: String): String = call("/query", null, json)

    private fun call(path: String, wrapper: String?, json: String): String {
        return try {
            val uri = android.net.Uri.parse(topUrl() ?: "")
            require(uri.scheme == "https" &&
                (uri.host == MainActivity.BASE_HOST || uri.host?.endsWith(".${MainActivity.BASE_HOST}") == true)) {
                "Local Core bridge is unavailable outside TaxNest"
            }
            val parsed = JSONObject(json)
            val request = if (wrapper == null) parsed else JSONObject().put(wrapper, parsed)
            LocalCoreClient.request(context, path, request).toString()
        } catch (e: Exception) {
            JSONObject().put("ok", false)
                .put("error", e.message ?: "local_core_request_failed").toString()
        }
    }
}