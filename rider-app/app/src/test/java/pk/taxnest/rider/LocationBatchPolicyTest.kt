package pk.taxnest.rider

import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test

class LocationBatchPolicyTest {
    private fun batch(vararg at: Long) = JSONArray().also { a -> at.forEach { a.put(JSONObject().put("at", it)) } }
    @Test fun live_fix_leads_old_backlog_without_dropping_history() {
        val selected = LocationBatchPolicy.select(batch(10, 20, 30, 500), 3)
        assertEquals(listOf(500L, 10L, 20L), (0 until selected.length()).map { selected.getJSONObject(it).getLong("at") })
    }
    @Test fun late_provider_callback_does_not_claim_newest_identity() {
        assertEquals(500L, LocationBatchPolicy.select(batch(10, 500, 20), 1).getJSONObject(0).getLong("at"))
    }
    @Test fun explicit_ack_is_intersected_with_this_request() {
        val response = JSONObject().put("ok", true).put("acknowledged_at", JSONArray(listOf(10L, 999L)))
        assertEquals(setOf(10L), LocationBatchPolicy.acknowledged(batch(10, 20), response))
    }
    @Test fun legacy_partial_count_never_deletes_unidentified_points() {
        assertNull(LocationBatchPolicy.acknowledged(batch(500, 10, 20), JSONObject().put("ok", true).put("stored", 2)))
    }
    @Test fun legacy_full_acceptance_and_terminal_rejection_are_compatible() {
        for (stored in listOf(0, 2)) assertEquals(setOf(10L, 20L), LocationBatchPolicy.acknowledged(batch(10, 20), JSONObject().put("ok", true).put("stored", stored)))
        assertNull(LocationBatchPolicy.acknowledged(batch(10), JSONObject().put("stored", 1)))
    }
}
