package pk.taxnest.rider

import android.location.Location
import android.location.LocationManager

/**
 * Proximity helper for Task #1508 — tells the delivery card whether the rider
 * is within the arrival radius of the destination.
 *
 * Accuracy-aware: a fix with reported accuracy worse than the arrival radius is
 * treated as "unknown" (returns null) so we never show a false-positive banner.
 */
object ArrivalDetector {

    /**
     * Returns the distance in metres from [riderLat]/[riderLng] to the
     * destination in [meta], or null if either position is unknown / meta has
     * no valid coordinates.
     */
    fun distanceTo(
        riderLat: Double?,
        riderLng: Double?,
        meta: DestinationMeta
    ): Float? {
        if (riderLat == null || riderLng == null) return null
        if (!meta.hasCoords) return null
        val result = FloatArray(1)
        Location.distanceBetween(riderLat, riderLng, meta.lat!!, meta.lng!!, result)
        return result[0]
    }

    /**
     * True when the rider is within the arrival radius AND the GPS accuracy is
     * good enough to trust the result.
     *
     * @param accuracyM reported GPS accuracy (null = unknown — treat as bad).
     */
    fun isNearArrival(
        riderLat: Double?,
        riderLng: Double?,
        accuracyM: Float?,
        meta: DestinationMeta
    ): Boolean {
        val dist = distanceTo(riderLat, riderLng, meta) ?: return false
        // Unknown accuracy is not safe enough for an arrival claim.
        // Smaller reported values are better.
        if (accuracyM == null || accuracyM > meta.arrivalRadiusM) return false
        return dist <= meta.arrivalRadiusM
    }
}
