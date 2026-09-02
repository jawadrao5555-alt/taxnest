package pk.taxnest.rider

/**
 * UI-thread state guard for bill-preview requests. A response is usable only
 * for the exact assignment and card generation that started it.
 */
class BillPreviewSafety {
    data class Assignment(val transactionId: Int, val revision: String)
    data class Request internal constructor(
        val assignment: Assignment,
        internal val generation: Long
    )

    private var generation = 0L
    private var resumed = false
    private var currentAssignments: Set<Assignment> = emptySet()

    fun resume() {
        resumed = true
    }

    /** Invalidates outstanding requests without trusting their eventual result. */
    fun invalidateLifecycle() {
        resumed = false
        generation++
    }

    /** Every /me replacement is a new card generation. */
    fun replaceCards(assignments: Set<Assignment>) {
        generation++
        currentAssignments = assignments
    }

    fun begin(transactionId: Int, revision: String): Request? {
        val assignment = Assignment(transactionId, revision)
        return if (resumed && transactionId > 0 && revision.isNotBlank() &&
            currentAssignments.contains(assignment)) Request(assignment, generation) else null
    }

    fun isCurrent(request: Request): Boolean =
        resumed && request.generation == generation && currentAssignments.contains(request.assignment)

    companion object {
        /** An available bill must not render a blank/missing item-name line. */
        fun hasRequiredItemNames(names: Iterable<String?>): Boolean =
            names.all { !it.isNullOrBlank() }
    }
}