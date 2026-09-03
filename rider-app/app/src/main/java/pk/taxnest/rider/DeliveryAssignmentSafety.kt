package pk.taxnest.rider

import org.json.JSONArray

/** Pure assignment identities shared by notification and stale-request guards. */
object DeliveryAssignmentSafety {
    data class Assignment(
        val id: Int,
        val revision: String?,
        val status: String
    )

    fun identity(id: String, revision: String?): String =
        "$id:${revision?.trim().orEmpty()}"

    fun currentAssignments(arr: JSONArray): Set<String> {
        val assignments = ArrayList<Assignment>(arr.length())
        for (i in 0 until arr.length()) {
            val item = arr.optJSONObject(i) ?: continue
            assignments.add(
                Assignment(
                    id = item.optInt("id", 0),
                    revision = item.optString("assignment_revision"),
                    status = item.optString("status")
                )
            )
        }
        return currentAssignmentIdentities(assignments)
    }

    fun currentAssignmentIdentities(assignments: Iterable<Assignment>): Set<String> {
        val result = LinkedHashSet<String>()
        for (assignment in assignments) {
            val revision = assignment.revision?.trim().orEmpty()
            if (assignment.id > 0 && revision.isNotEmpty() &&
                (assignment.status == "assigned" || assignment.status == "dispatched")) {
                result.add(identity(assignment.id.toString(), revision))
            }
        }
        return result
    }
}