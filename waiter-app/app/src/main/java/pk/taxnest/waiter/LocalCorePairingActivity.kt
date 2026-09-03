package pk.taxnest.waiter

import android.app.Activity
import android.os.Bundle
import android.view.Gravity
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.codescanner.GmsBarcodeScannerOptions
import com.google.mlkit.vision.codescanner.GmsBarcodeScanning
import kotlin.concurrent.thread

class LocalCorePairingActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val status = TextView(this)
        val name = EditText(this).apply { hint = "Device name"; setText(android.os.Build.MODEL.take(60)) }
        val raw = EditText(this).apply { hint = "Pairing payload (scan QR or paste)"; minLines = 5 }
        val scan = Button(this).apply { text = "Scan pairing QR" }
        val pair = Button(this).apply { text = "Pair securely" }
        setContentView(LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_HORIZONTAL
            setPadding(32, 48, 32, 32)
            addView(name); addView(raw); addView(scan); addView(pair); addView(status)
        })
        scan.setOnClickListener {
            val options = GmsBarcodeScannerOptions.Builder()
                .setBarcodeFormats(Barcode.FORMAT_QR_CODE).enableAutoZoom().build()
            GmsBarcodeScanning.getClient(this, options).startScan()
                .addOnSuccessListener { raw.setText(it.rawValue ?: "") }
                .addOnFailureListener { status.text = it.message ?: "Scanner failed" }
        }
        pair.setOnClickListener {
            pair.isEnabled = false
            status.text = "Verifying PC certificate…"
            thread {
                try {
                    val payload = LocalCoreClient.parsePayload(raw.text.toString())
                    LocalCoreClient.pair(this, payload, name.text.toString())
                    runOnUiThread { status.text = "Paired securely"; pair.isEnabled = true }
                } catch (e: Exception) {
                    runOnUiThread { status.text = e.message ?: "Pairing failed"; pair.isEnabled = true }
                }
            }
        }
    }
}