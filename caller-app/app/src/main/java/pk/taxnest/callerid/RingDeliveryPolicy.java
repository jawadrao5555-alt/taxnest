package pk.taxnest.callerid;

/** A 2xx transport response is not necessarily permission or delivery. */
public final class RingDeliveryPolicy {
    public enum Result { DELIVERED, DENIED, UNAUTHORIZED, DROP, RETRY }
    public static Result cloud(int code, boolean ok, Boolean accepted, String reason) {
        if(code==401) return Result.UNAUTHORIZED;
        if(code==422) return Result.DROP;
        if(code==403 || code==410) return Result.DENIED;
        if(code>=200 && code<=299 && ok) {
            if(Boolean.TRUE.equals(accepted) || (Boolean.FALSE.equals(accepted) && "duplicate".equals(reason))) return Result.DELIVERED;
            if(Boolean.FALSE.equals(accepted)) return Result.DENIED;
        }
        return Result.RETRY;
    }
}
