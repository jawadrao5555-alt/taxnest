package pk.taxnest.callerid;

import org.junit.Test;
import static org.junit.Assert.*;
import java.nio.file.*;
import java.util.Comparator;

public class RingRecoveryTest {
    @Test public void persistedCallRetainsIdentityAndTimeUntilAck() throws Exception {
        Path dir=Files.createTempDirectory("caller-unit-");
        try {
            RingOutbox box=new RingOutbox(dir.toFile());
            String scope=RingOutbox.scope("fictional-token");long at=1_790_000_000_000L;
            RingOutbox.Event event=box.enqueue(scope,"lan","03000000001","","sim",at);
            RingOutbox.Event restored=new RingOutbox(dir.toFile()).pending(scope,at+10).get(0);
            assertEquals(event.uuid,restored.uuid);assertEquals(at,restored.at);
            assertFalse(restored.mayUseLan("lan",at+120_000));
            box.acknowledge(scope,event.uuid,at+20);
            assertTrue(new RingOutbox(dir.toFile()).pending(scope,at+30).isEmpty());
        } finally { try(java.util.stream.Stream<Path> paths=Files.walk(dir)){paths.sorted(Comparator.reverseOrder()).forEach(p->{try{Files.delete(p);}catch(Exception e){throw new RuntimeException(e);}});} }
    }
    @Test public void packageDenialIsNotASuccessOrNetworkFailure() {
        assertEquals(RingDeliveryPolicy.Result.DENIED,RingDeliveryPolicy.cloud(200,true,false,"plan_locked"));
        assertEquals(RingDeliveryPolicy.Result.DENIED,RingDeliveryPolicy.cloud(200,true,false,"disabled"));
        assertEquals(RingDeliveryPolicy.Result.DELIVERED,RingDeliveryPolicy.cloud(200,true,false,"duplicate"));
        assertEquals(RingDeliveryPolicy.Result.RETRY,RingDeliveryPolicy.cloud(503,false,null,""));
        assertEquals(RingDeliveryPolicy.Result.UNAUTHORIZED,RingDeliveryPolicy.cloud(401,false,null,""));
    }
}
