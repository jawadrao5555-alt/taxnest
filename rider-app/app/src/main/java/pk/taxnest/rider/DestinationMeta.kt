package pk.taxnest.rider

import org.json.JSONObject

/**
 * Destination metadata parsed from a /me delivery item (Task #1508).
 *
 * Fields are backward-compatible — any missing key returns null/default so old
 * server responses never crash.  The server may add these fields gradually; the
 * app gracefully degrades to the old address/maps_url behaviour when absent.
 */
data class DestinationMeta(
    val lat: Double?,
    val lng: Double?,
    val placeType: String?,         // "home" | "business" | "other" | null
    val placeLabel: String?,        // optional free-text label ≤80 chars
    val arrivalRadiusM: Int,        // metres for "near arrival" badge; default 150
    val assignmentRevision: String  // opaque server revision; blank = legacy server
) {
    val hasCoords: Boolean get() = lat != null && lng != null

    companion object {
        fun from(item: JSONObject): DestinationMeta {
            val lat = if (item.has("destination_lat") && !item.isNull("destination_lat"))
                item.optDouble("destination_lat") else null
            val lng = if (item.has("destination_lng") && !item.isNull("destination_lng"))
                item.optDouble("destination_lng") else null
            // Clamp lat/lng to valid ranges; treat 0.0/0.0 (sentinel) as absent.
            val validLat = lat?.takeIf { it >= -90.0 && it <= 90.0 && it != 0.0 }
            val validLng = lng?.takeIf { it >= -180.0 && it <= 180.0 && it != 0.0 }
            return DestinationMeta(
                lat = validLat,
                lng = validLng,
                placeType = item.optString("place_type").ifBlank { null },
                placeLabel = item.optString("place_label").ifBlank { null }
                    ?.take(80),
                arrivalRadiusM = item.optInt("arrival_radius_m", 150).coerceAtLeast(30),
                assignmentRevision = item.optString("assignment_revision").trim()
            )
        }
    }
}
