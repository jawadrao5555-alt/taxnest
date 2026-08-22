package pk.taxnest.rider

import android.content.Context
import org.json.JSONObject

/**
 * Drains the delivery-completion outbox (Task #1508).
 *
 * Called from:
 *  - OutboxWorker (WorkManager — the durable offline path)
 *  - MainActivity after a successful in-app confirmation (fast path)
 *  - Connectivity-restored callback
 *
 * Success criteria:
 *  - 2xx → confirmed, remove from outbox.
 *  - 404/410 → bill is gone or no longer assigned, remove.
 *  - 409 gps_not_synced → location upload has not reached the server yet, retry.
 *  - Other 409 and all 422 → permanent revision/evidence rejection, remove.
 *  - 401 → token gone, do not retry.
 *  - Everything else (network error, 5xx) → leave in outbox for retry.
 */
object OutboxDrain {

    fun drainBlocking(context: Context) {
        val c = context.applicationContext
        val token = Prefs.token(c) ?: return
        val pending = DeliveryOutbox.peek(c)
        for (i in 0 until pending.length()) {
            val entry = pending.optJSONObject(i) ?: continue
            val txnId = entry.optInt("txn_id", -1)
            if (txnId <= 0) {
                // Malformed entry — discard.
                DeliveryOutbox.remove(c, txnId)
                continue
            }
            val payload = buildPayload(entry)
            val (code, body) = ApiClient.post(
                "/deliveries/$txnId/delivered",
                payload,
                token
            )
            when {
                code in 200..299 -> {
                    // Success — remove from outbox.
                    DeliveryOutbox.remove(c, txnId)
                }
                code == 409 && body?.optString("error") == "gps_not_synced" -> {
                    // The GPS batch may still be queued. Leave evidence intact
                    // and retry once location sync reaches the server.
                }
                code == 409 || code == 410 || code == 422 || code == 403 -> {
                    // Assignment changed, evidence is permanently invalid, the
                    // bill is gone, or the package is locked.
                    DeliveryOutbox.remove(c, txnId)
                }
                code == 404 -> {
                    // Bill no longer assigned to this rider — permanent, remove.
                    DeliveryOutbox.remove(c, txnId)
                }
                code == 401 -> {
                    // Token rotated — stop draining entirely.
                    Prefs.clearToken(c)
                    return
                }
                // 5xx, -1 (network): leave in outbox, WorkManager will retry.
            }
        }
    }

    /** Exposed for MainActivity's fast-path delivery submission. */
    fun buildDeliveryPayload(entry: JSONObject): JSONObject = buildPayload(entry)

    private fun buildPayload(entry: JSONObject): JSONObject {
        val p = JSONObject()
        p.put("client_event_id", entry.optString("client_event_id"))
        p.put("place_type", entry.optString("place_type", "other"))
        val label = entry.optString("place_label")
        if (label.isNotBlank()) p.put("place_label", label)
        val lat = entry.optDouble("lat", Double.NaN)
        val lng = entry.optDouble("lng", Double.NaN)
        if (!lat.isNaN()) p.put("lat", lat)
        if (!lng.isNaN()) p.put("lng", lng)
        val acc = entry.optDouble("accuracy_m", Double.NaN)
        if (!acc.isNaN()) p.put("accuracy_m", acc)
        p.put("captured_at", entry.optLong("captured_at", 0L))
        val revision = entry.optString("assignment_revision")
        if (revision.isNotBlank()) p.put("assignment_revision", revision)
        return p
    }
}
