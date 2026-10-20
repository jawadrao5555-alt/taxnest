package pk.taxnest.rider

import org.json.JSONArray
import org.json.JSONObject

/** Timestamp identities let a live fix jump the backlog without deleting it. */
object LocationBatchPolicy {
    fun select(queue: JSONArray, max: Int): JSONArray {
        val out = JSONArray()
        if (max <= 0 || queue.length() == 0) return out
        // Newest by CAPTURE TIME, even when providers deliver callbacks late.
        val newest = (0 until queue.length()).maxByOrNull { queue.optJSONObject(it)?.optLong("at", 0L) ?: 0L }!!
        out.put(queue.get(newest))
        for (i in 0 until queue.length()) {
            if (out.length() >= max) break
            if (i != newest) out.put(queue.get(i))
        }
        return out
    }

    fun acknowledged(batch: JSONArray, response: JSONObject): Set<Long>? {
        if (!response.optBoolean("ok", false)) return null
        val sent = (0 until batch.length()).mapNotNull {
            batch.optJSONObject(it)?.optLong("at", 0L)?.takeIf { at -> at > 0L }
        }.toSet()
        val explicit = response.optJSONArray("acknowledged_at")
        if (explicit != null) return (0 until explicit.length()).map { explicit.optLong(it, 0L) }.toSet().intersect(sent)
        // Old servers cannot identify a partial acceptance. Retain and retry;
        // never infer a prefix from count after prioritizing a recent point.
        return if (response.optInt("stored", -1) == batch.length() || response.optInt("stored", -1) == 0) sent else null
    }
}
