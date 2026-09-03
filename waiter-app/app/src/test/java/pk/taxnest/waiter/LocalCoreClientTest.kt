package pk.taxnest.waiter

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class LocalCoreClientTest {
    private fun payload(url: String = "https://192.168.1.8:8532", expires: Long = 1_500_000L) =
        """{"v":1,"url":"$url","spki_sha256":"spki","cert_sha256":"cert","code":"123456","nonce":"nonce","waiter_lease":"opaque-waiter-lease-proof","expires_at":$expires}"""

    @Test fun acceptsPrivatePinnedHttpsPayload() {
        val parsed = LocalCoreClient.parsePayload(payload(), 1_000_000L)
        assertEquals("123456", parsed.code)
        assertEquals("opaque-waiter-lease-proof", parsed.waiterLease)
    }

    @Test fun rejectsCleartextPublicAndExpiredPayloads() {
        assertThrows(IllegalArgumentException::class.java) {
            LocalCoreClient.parsePayload(payload("http://192.168.1.8:8532"), 1_000_000L)
        }
        assertThrows(IllegalArgumentException::class.java) {
            LocalCoreClient.parsePayload(payload("https://8.8.8.8:8532"), 1_000_000L)
        }
        assertThrows(IllegalArgumentException::class.java) {
            LocalCoreClient.parsePayload(payload(expires = 999_999L), 1_000_000L)
        }
    }

    @Test fun waiterLeastPrivilegePolicy() {
        val basic = emptySet<String>()
        assertEquals(true, LocalCoreClient.isCommandAllowed("waiter", basic, "order.hold"))
        assertEquals(false, LocalCoreClient.isCommandAllowed("waiter", basic, "order.open"))
        assertEquals(false, LocalCoreClient.isCommandAllowed("waiter", basic, "order.line.add"))
        assertEquals(false, LocalCoreClient.isCommandAllowed("waiter", basic, "order.line.consume"))
        assertEquals(true, LocalCoreClient.isCommandAllowed("waiter", basic, "table.claim"))
        assertEquals(false, LocalCoreClient.isCommandAllowed("waiter", basic, "order.settle"))
        assertEquals(false, LocalCoreClient.isCommandAllowed("waiter", basic, "order.cancel"))
        assertEquals(true, LocalCoreClient.isCommandAllowed("waiter", setOf("order.settle"), "order.settle"))
        assertEquals(false, LocalCoreClient.isCommandAllowed("cashier", setOf("*"), "order.hold"))
        assertEquals(true, LocalCoreClient.isCredentialBindingValid("waiter-7", "waiter-7", "waiter", false))
        assertEquals(false, LocalCoreClient.isCredentialBindingValid("waiter-7", "waiter-8", "waiter", false))
        assertEquals(false, LocalCoreClient.isCredentialBindingValid("waiter-7", "waiter-7", "waiter", true))
    }
}