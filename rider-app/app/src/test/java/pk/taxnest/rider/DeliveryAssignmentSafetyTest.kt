package pk.taxnest.rider

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Test

class DeliveryAssignmentSafetyTest {
    @Test fun reassignment_changes_identity_even_for_same_bill() {
        assertNotEquals(
            DeliveryAssignmentSafety.identity("42", "old"),
            DeliveryAssignmentSafety.identity("42", "new")
        )
    }

    @Test fun only_current_open_revision_is_retained() {
        val assignments = listOf(
            DeliveryAssignmentSafety.Assignment(42, "new", "assigned"),
            DeliveryAssignmentSafety.Assignment(43, "done", "delivered"),
            DeliveryAssignmentSafety.Assignment(44, "", "assigned")
        )
        assertEquals(
            setOf("42:new"),
            DeliveryAssignmentSafety.currentAssignmentIdentities(assignments)
        )
    }
}