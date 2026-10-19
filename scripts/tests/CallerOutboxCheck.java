import javax.tools.ToolProvider;
import java.net.URLClassLoader;
import java.nio.file.*;
import java.util.Comparator;

/** Source launcher uses the JDK compiler even on images without a javac binary. */
class CallerOutboxCheck {
    public static void main(String[] args) throws Exception {
        Path root=Path.of(args.length==0 ? "." : args[0]).toAbsolutePath();
        Path output=Files.createTempDirectory("caller-outbox-tests-");
        try {
            String src=root.resolve("caller-app/app/src/main/java/pk/taxnest/callerid/").toString()+"/";
            int result=ToolProvider.getSystemJavaCompiler().run(null,System.out,System.err,"--release","8","-d",output.toString(),
                src+"RingOutbox.java",src+"RingDeliveryPolicy.java",root.resolve("caller-app/tests/RingOutboxBehaviorTest.java").toString());
            if(result!=0) throw new AssertionError("Caller production/test compilation failed: "+result);
            try(URLClassLoader loader=new URLClassLoader(new java.net.URL[]{output.toUri().toURL()})) {
                loader.loadClass("RingOutboxBehaviorTest").getMethod("main",String[].class).invoke(null,(Object)new String[0]);
            }
        } finally { try(var paths=Files.walk(output)) { paths.sorted(Comparator.reverseOrder()).forEach(p->{try{Files.delete(p);}catch(Exception e){throw new RuntimeException(e);}}); } }
    }
}
