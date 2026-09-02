package pk.taxnest.rider

import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

class BillPreviewSafetyTest {
    private val assignment = BillPreviewSafety.Assignment(42, "revision-a")

    @Test fun stale_response_is_rejected_after_card_replacement() {
        val guard = BillPreviewSafety()
        guard.resume()
        guard.replaceCards(setOf(assignment))
        val request = guard.begin(42, "revision-a")
        assertNotNull(request)
        guard.replaceCards(setOf(assignment))
        assertFalse(guard.isCurrent(request!!))
    }

    @Test fun reassignment_with_new_revision_rejects_old_response() {
        val guard = BillPreviewSafety()
        guard.resume()
        guard.replaceCards(setOf(assignment))
        val request = guard.begin(42, "revision-a")!!
        guard.replaceCards(setOf(BillPreviewSafety.Assignment(42, "revision-b")))
        assertFalse(guard.isCurrent(request))
    }

    @Test fun lifecycle_invalidation_rejects_response_until_a_new_request() {
        val guard = BillPreviewSafety()
        guard.resume()
        guard.replaceCards(setOf(assignment))
        val oldRequest = guard.begin(42, "revision-a")!!
        guard.invalidateLifecycle()
        assertFalse(guard.isCurrent(oldRequest))
        guard.resume()
        val newRequest = guard.begin(42, "revision-a")!!
        assertTrue(guard.isCurrent(newRequest))
    }

    @Test fun missing_or_blank_item_names_fail_the_preview_contract() {
        assertTrue(BillPreviewSafety.hasRequiredItemNames(listOf("Pizza", "Tea")))
        assertFalse(BillPreviewSafety.hasRequiredItemNames(listOf("Pizza", null)))
        assertFalse(BillPreviewSafety.hasRequiredItemNames(listOf("Pizza", "  ")))
    }
}