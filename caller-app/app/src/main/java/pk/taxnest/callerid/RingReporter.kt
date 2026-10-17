package pk.taxnest.callerid

import android.content.Context
import android.util.Log
import org.json.JSONObject
import kotlin.concurrent.thread

/** Shared SIM/plus/Play durable transport; detection rules remain in RingCoordinator. */
object RingReporter {
    private val deliveryLock = Any()
    private fun outbox(ctx: Context) = RingOutbox(ctx.noBackupFilesDir)
    private fun lanScope(ctx: Context): String = if (LanClient.isPaired(ctx))
        RingOutbox.scope((Prefs.lanHost(ctx)?.trim() ?: "") + "|" + (Prefs.lanToken(ctx) ?: "")) else ""

    /** Persist before network/dedupe acknowledgement, even in a short-lived receiver. */
    fun report(context: Context, rawPhone: String?, rawName: String?, source: String) {
        val ctx = context.applicationContext
        val token = Prefs.token(ctx) ?: return
        val phone = rawPhone?.replace(Regex("[^+0-9]"), "")?.takeIf { it.length >= 9 }?.take(40) ?: ""
        val name = rawName?.trim()?.take(120) ?: ""
        if (phone.isEmpty() && name.isEmpty()) return
        try {
            synchronized(deliveryLock) {
                if (Prefs.token(ctx) != token) return
                outbox(ctx).enqueue(RingOutbox.scope(token), lanScope(ctx), phone, name,
                    if (source == "whatsapp") "whatsapp" else "sim", System.currentTimeMillis())
                // Schedule BEFORE trying a lane: process death must not strand the snapshot.
                RingRetryJob.schedule(ctx)
            }
            drain(ctx)
        } catch (e: Exception) {
            // No phone/name/token in diagnostics. Never silently overwrite a corrupt/full queue.
            Log.w("CallerOutbox", "Ring persistence/delivery needs attention", e)
        }
    }

    /** Bounded drain; retries retain original identity/time. true means more work remains. */
    fun drain(context: Context, stopped: () -> Boolean = { false }): Boolean = synchronized(deliveryLock) {
        val ctx = context.applicationContext
        val token = Prefs.token(ctx) ?: return@synchronized false
        val scope = RingOutbox.scope(token)
        val box = outbox(ctx)
        try {
            val pending = box.pending(scope, System.currentTimeMillis())
            for (event in pending.take(5)) {
                if (stopped() || Prefs.token(ctx) != token) break
                val now = System.currentTimeMillis()
                val lanReady = event.mayUseLan(lanScope(ctx), now) && Prefs.featureEnabled(ctx)
                val cloudFirst = !lanReady || LanClient.looksOnline(ctx)
                val result = if (!cloudFirst && postLan(ctx, event)) {
                    RingDeliveryPolicy.Result.DELIVERED
                } else {
                    val cloud = postCloud(token, event)
                    // Explicit disabled/plan/revocation responses never fall through to LAN.
                    if (cloud == RingDeliveryPolicy.Result.RETRY && cloudFirst && lanReady &&
                        Prefs.token(ctx) == token && postLan(ctx, event)) RingDeliveryPolicy.Result.DELIVERED else cloud
                }
                if (Prefs.token(ctx) != token) return@synchronized false
                when (result) {
                    RingDeliveryPolicy.Result.DELIVERED -> {
                        box.acknowledge(scope, event.uuid, now)
                        Prefs.setLastSentAt(ctx, now)
                        Prefs.setFeatureEnabled(ctx, true)
                    }
                    RingDeliveryPolicy.Result.DENIED -> {
                        // Feature/package is authoritative. Do not backlog calls made while disabled.
                        box.clear(scope, now)
                        Prefs.setFeatureEnabled(ctx, false)
                        return@synchronized false
                    }
                    RingDeliveryPolicy.Result.UNAUTHORIZED -> {
                        box.clear(scope, now)
                        Prefs.setToken(ctx, null)
                        return@synchronized false
                    }
                    RingDeliveryPolicy.Result.DROP -> box.acknowledge(scope, event.uuid, now)
                    RingDeliveryPolicy.Result.RETRY -> return@synchronized true
                }
            }
            box.pending(scope, System.currentTimeMillis()).isNotEmpty()
        } catch (e: Exception) {
            Log.w("CallerOutbox", "Saved queue recovery needs attention", e)
            true
        }
    }

    private fun postCloud(token: String, event: RingOutbox.Event): RingDeliveryPolicy.Result {
        val payload = JSONObject().put("phone", event.phone.ifEmpty { null } ?: JSONObject.NULL)
            .put("name", event.name.ifEmpty { null } ?: JSONObject.NULL).put("source", event.source)
            .put("at", event.at).put("uuid", event.uuid)
        val (code, body) = ApiClient.post("/ring", payload, token)
        val accepted = if (body != null && body.has("accepted") && !body.isNull("accepted")) body.optBoolean("accepted") else null
        return RingDeliveryPolicy.cloud(code, body?.optBoolean("ok") == true, accepted, body?.optString("reason") ?: "")
    }

    private fun postLan(ctx: Context, event: RingOutbox.Event): Boolean {
        val ok = try { LanClient.ring(ctx, event.uuid, event.phone.ifEmpty { null }, event.name.ifEmpty { null }, event.source, expectedPairing = event.lanScope) }
            catch (_: Exception) { false }
        if (ok) Prefs.setLanLastOkAt(ctx, System.currentTimeMillis())
        return ok
    }

    fun reportAsync(context: Context, rawPhone: String?, rawName: String?, source: String) {
        thread(name = "caller-ring-post") { report(context, rawPhone, rawName, source) }
    }

    fun resume(context: Context) {
        val ctx = context.applicationContext
        if (Prefs.token(ctx) == null) return
        thread(name = "caller-ring-recover") {
            if (drain(ctx)) RingRetryJob.schedule(ctx)
        }
    }
}
