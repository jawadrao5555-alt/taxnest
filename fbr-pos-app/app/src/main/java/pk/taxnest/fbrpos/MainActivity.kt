package pk.taxnest.fbrpos

import android.annotation.SuppressLint
import android.app.Activity
import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.Message
import android.view.View
import android.widget.FrameLayout
import android.webkit.CookieManager
import android.webkit.DownloadListener
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast

/**
 * TaxNest POS (PRA) — thin WebView shell around https://taxnest.com.pk.
 *
 * Deliberately "dumb": ALL product logic lives on the server, so web deploys
 * update the app instantly and this APK almost never needs a re-release.
 * Every POS role logs in with their normal web credentials — owner, admin,
 * cashier, waiter, kitchen, rider — and lands on their own role-based screen,
 * exactly like the website (server-side routing decides, not the app).
 *
 * What the shell DOES handle natively:
 *  - keeping navigation inside taxnest.com.pk (everything else → system apps:
 *    tel:, WhatsApp, external sites)
 *  - target=_blank popups (receipt PDFs etc.) routed back into the same view
 *  - file downloads (PDF/Excel exports) via DownloadManager WITH session
 *    cookies (authenticated URLs)
 *  - file uploads (product photos, payment proofs) via the system picker
 *  - hardware back = web history back (never accidental exit)
 *  - offline error page in Urdu with a retry button
 *  - rotation survives without reloading (configChanges in the manifest) so
 *    a half-built bill is never lost
 */
class MainActivity : Activity() {

    companion object {
        const val BASE_HOST = "taxnest.com.pk"
        const val START_URL = "https://taxnest.com.pk/fbr-pos/login"
        const val FILE_PICK_REQUEST = 71
    }

    private lateinit var web: WebView
    private var filePathCallback: ValueCallback<Array<Uri>>? = null
    private var lastMainFrameUrl: String = START_URL

    // Fullscreen <video> support (ported from pos-app v1.0.3): tutorial videos
    // ka fullscreen button WebView mein tab hi chalta hai jab shell
    // onShowCustomView de. Video ek overlay view ban kar aata hai.
    private var customVideoView: View? = null
    private var customViewCallback: WebChromeClient.CustomViewCallback? = null

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        web = WebView(this)
        setContentView(web)

        with(web.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            loadWithOverviewMode = true
            useWideViewPort = true
            setSupportMultipleWindows(true)
            javaScriptCanOpenWindowsAutomatically = true
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            // Server-side detection hook (e.g. hide "download our app" banners,
            // show "new APK available" update banner). MUST carry the real
            // versionName so the server can compare against the latest release.
            userAgentString = "$userAgentString TaxNestFBRPosApp/${BuildConfig.VERSION_NAME}"
        }

        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(web, false)

        web.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                return routeUrl(request.url)
            }

            override fun onPageStarted(view: WebView, url: String, favicon: android.graphics.Bitmap?) {
                if (url.startsWith("http")) lastMainFrameUrl = url
                super.onPageStarted(view, url, favicon)
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                // Only take over the screen for MAIN-frame failures (not a
                // missing image/beacon) — sub-resource errors must never nuke
                // a working page.
                if (request.isForMainFrame) {
                    showOfflinePage()
                }
                super.onReceivedError(view, request, error)
            }
        }

        web.webChromeClient = object : WebChromeClient() {
            // Fullscreen video (ported from pos-app v1.0.3): bina in overrides
            // ke video ka fullscreen button dabane par kuch NAHI hota (WebView
            // default = unsupported). Video view decor par overlay hota hai,
            // system bars chhup jaati hain; back/exit par sab waisa hi wapas.
            override fun onShowCustomView(view: View, callback: CustomViewCallback) {
                if (customVideoView != null) { callback.onCustomViewHidden(); return }
                customVideoView = view
                customViewCallback = callback
                web.visibility = View.GONE
                (window.decorView as FrameLayout).addView(
                    view,
                    FrameLayout.LayoutParams(
                        FrameLayout.LayoutParams.MATCH_PARENT,
                        FrameLayout.LayoutParams.MATCH_PARENT
                    )
                )
                @Suppress("DEPRECATION")
                window.decorView.systemUiVisibility =
                    View.SYSTEM_UI_FLAG_FULLSCREEN or
                    View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or
                    View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
            }

            override fun onHideCustomView() {
                val v = customVideoView ?: return
                (window.decorView as FrameLayout).removeView(v)
                customVideoView = null
                customViewCallback?.onCustomViewHidden()
                customViewCallback = null
                web.visibility = View.VISIBLE
                @Suppress("DEPRECATION")
                window.decorView.systemUiVisibility = 0
            }

            override fun onShowFileChooser(
                view: WebView,
                callback: ValueCallback<Array<Uri>>,
                params: FileChooserParams
            ): Boolean {
                filePathCallback?.onReceiveValue(null)
                filePathCallback = callback
                val intent = Intent(Intent.ACTION_GET_CONTENT).apply {
                    addCategory(Intent.CATEGORY_OPENABLE)
                    type = "*/*"
                    putExtra(Intent.EXTRA_ALLOW_MULTIPLE, false)
                }
                return try {
                    startActivityForResult(Intent.createChooser(intent, null), FILE_PICK_REQUEST)
                    true
                } catch (e: Exception) {
                    filePathCallback = null
                    false
                }
            }

            // target=_blank (receipt PDFs, share pages): capture the URL via a
            // throwaway transport WebView, then route it like any other link.
            override fun onCreateWindow(
                view: WebView,
                isDialog: Boolean,
                isUserGesture: Boolean,
                resultMsg: Message
            ): Boolean {
                val transport = resultMsg.obj as WebView.WebViewTransport
                val temp = WebView(this@MainActivity)
                temp.webViewClient = object : WebViewClient() {
                    override fun shouldOverrideUrlLoading(v: WebView, r: WebResourceRequest): Boolean {
                        if (!routeUrl(r.url)) {
                            web.loadUrl(r.url.toString())
                        }
                        v.destroy()
                        return true
                    }
                }
                transport.webView = temp
                resultMsg.sendToTarget()
                return true
            }
        }

        web.setDownloadListener(DownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            try {
                // SECURITY: DownloadManager re-sends custom headers on redirects,
                // so the session cookie must (a) only ever be attached for
                // first-party URLs and (b) NEVER for the desktop-agent installer
                // routes — those 302 to GitHub release assets and would carry the
                // cookie off-site. Agent links are rewritten to the public,
                // cookie-less /download/agent endpoint instead.
                val srcUri = Uri.parse(url)
                val host = srcUri.host ?: ""
                val firstParty = host == BASE_HOST || host.endsWith(".$BASE_HOST")
                val path = srcUri.path ?: ""
                val agentInstaller = path.startsWith("/download/agent") ||
                    path.startsWith("/pos/agent/download") ||
                    path.startsWith("/fbr-pos/agent/download")
                val dlUrl = if (firstParty && agentInstaller) {
                    "https://$BASE_HOST/download/agent" + (srcUri.query?.let { "?$it" } ?: "")
                } else url
                val request = DownloadManager.Request(Uri.parse(dlUrl)).apply {
                    // Session cookie is REQUIRED for exports/receipts behind login —
                    // but strictly first-party, and never on installer redirects.
                    if (firstParty && !agentInstaller) {
                        CookieManager.getInstance().getCookie(url)?.let { addRequestHeader("Cookie", it) }
                    }
                    addRequestHeader("User-Agent", userAgent)
                    val fileName = URLUtil.guessFileName(dlUrl, contentDisposition, mimeType)
                    setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                    if (Build.VERSION.SDK_INT >= 29) {
                        setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
                    } else {
                        // Pre-29 public dir needs a storage permission — use the
                        // app dir instead (visible via file manager, no prompts).
                        setDestinationInExternalFilesDir(this@MainActivity, Environment.DIRECTORY_DOWNLOADS, fileName)
                    }
                    setMimeType(mimeType)
                }
                (getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
                Toast.makeText(this, getString(R.string.download_started), Toast.LENGTH_SHORT).show()
            } catch (e: Exception) {
                // Last resort: hand the URL to the system browser.
                try { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) } catch (_: Exception) {}
            }
        })

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState)
        } else {
            web.loadUrl(START_URL)
        }

        // Play-Store-jaisa update check (Task 443) — fail-silent, once per launch.
        UpdateCheck.run(this)
    }

    /** true = handled externally (or blocked); false = let the WebView load it. */
    private fun routeUrl(uri: Uri): Boolean {
        val scheme = uri.scheme ?: return true
        if (scheme == "http") return true // never downgrade to cleartext
        if (scheme == "https") {
            val host = uri.host ?: ""
            if (host == BASE_HOST || host.endsWith(".$BASE_HOST")) return false
        }
        // tel:, mailto:, whatsapp:, external https — system handles it.
        return try {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
            true
        } catch (e: Exception) {
            true // no handler installed — swallow rather than crash
        }
    }

    private fun showOfflinePage() {
        val html = """
            <!doctype html><html><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
              body{font-family:sans-serif;background:#0A4D5C;color:#fff;display:flex;
                   align-items:center;justify-content:center;height:95vh;margin:0;text-align:center}
              .card{padding:24px}
              h2{margin:0 0 8px;font-size:22px}
              p{margin:0 0 24px;opacity:.85;font-size:15px;line-height:1.6}
              button{background:#E7BF3B;color:#0A4D5C;border:0;border-radius:10px;
                     padding:14px 34px;font-size:16px;font-weight:bold}
            </style></head><body><div class="card">
              <h2>${getString(R.string.offline_title)}</h2>
              <p>${getString(R.string.offline_body)}</p>
              <button onclick="TNApp.retry()">${getString(R.string.offline_retry)}</button>
            </div></body></html>
        """.trimIndent()
        web.addJavascriptInterface(RetryBridge(), "TNApp")
        web.loadDataWithBaseURL(null, html, "text/html", "utf-8", null)
    }

    inner class RetryBridge {
        @android.webkit.JavascriptInterface
        fun retry() {
            runOnUiThread { web.loadUrl(lastMainFrameUrl) }
        }
    }

    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        if (requestCode == FILE_PICK_REQUEST) {
            val result = if (resultCode == RESULT_OK && data?.data != null) arrayOf(data.data!!) else null
            filePathCallback?.onReceiveValue(result)
            filePathCallback = null
            return
        }
        super.onActivityResult(requestCode, resultCode, data)
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        web.saveState(outState)
    }

    @Deprecated("Deprecated in Java")
    override fun onBackPressed() {
        // Fullscreen video chal rahi ho to back pehle usse band kare —
        // web history ya app se bahar nahi.
        if (customVideoView != null) {
            web.webChromeClient?.onHideCustomView()
            return
        }
        if (web.canGoBack()) web.goBack() else moveTaskToBack(true)
    }

    override fun onDestroy() {
        web.destroy()
        super.onDestroy()
    }
}
