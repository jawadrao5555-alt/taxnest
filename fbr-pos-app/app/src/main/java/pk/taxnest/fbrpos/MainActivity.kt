package pk.taxnest.fbrpos

import android.annotation.SuppressLint
import android.app.Activity
import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.os.Handler
import android.os.Looper
import android.os.Message
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import android.webkit.CookieManager
import android.webkit.DownloadListener
import android.webkit.RenderProcessGoneDetail
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebStorage
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast

/**
 * TaxNest FBR POS — thin WebView shell around https://taxnest.pk.
 *
 * Deliberately "dumb": ALL product logic lives on the server, so web deploys
 * update the app instantly and this APK almost never needs a re-release.
 * Every FBR POS role logs in with their normal web credentials — owner, admin,
 * cashier — and lands on their own role-based screen, exactly like the website
 * (server-side routing decides, not the app).
 *
 * What the shell DOES handle natively:
 *  - keeping navigation inside taxnest.pk (everything else → system apps:
 *    tel:, WhatsApp, external sites)
 *  - target=_blank popups (receipt PDFs etc.) routed back into the same view
 *  - file downloads (PDF/Excel exports) via DownloadManager WITH session
 *    cookies (authenticated URLs)
 *  - file uploads (product photos, payment proofs) via the system picker
 *  - hardware back = web history back (never accidental exit)
 *  - blank-screen recovery (see below)
 *  - rotation survives without reloading (configChanges in the manifest) so
 *    a half-built bill is never lost
 *
 * ── Blank-screen recovery (Task #1491) — SHARED by all four shells ──────────
 * A shop filmed the Waiter shell opening to a plain white window: the document
 * was fetched (it is in the live access log) but never parsed or painted, and
 * the shell had no way to notice — it only reacted to hard network errors, so
 * reload/back/reopen all restored the same dead screen.
 *
 * Every shell therefore now:
 *  1. shows a BRANDED boot screen (logo + spinner) from launch, hidden only
 *     when the page really paints (onPageCommitVisible), so an unpainted
 *     WebView can never look like a dead app;
 *  2. arms a WATCHDOG on every main-frame load — nothing painted by
 *     PAINT_TIMEOUT_MS while the boot screen is still up = failed load;
 *  3. PROBES a finished page for actual content and treats an empty document
 *     as a failure;
 *  4. treats a 5xx main-frame response as a failure and REBUILDS the WebView
 *     when Android kills the renderer;
 *  5. shows a RECOVERY CARD (retry / reset app data / technical reason) instead
 *     of a blank screen;
 *  6. RETRIES on resume when the current load never succeeded, so closing and
 *     reopening the app genuinely fixes it.
 * Keep this behaviour identical in pos-app, fbr-pos-app, di-app and waiter-app
 * (see docs/android-shell-recovery.md); a new clone must copy it as-is.
 */
class MainActivity : Activity() {

    companion object {
        const val BASE_HOST = "taxnest.pk"
        const val START_URL = "https://taxnest.pk/fbr-pos/login"
        const val FILE_PICK_REQUEST = 71
        const val REQ_NOTIF = 72

        // ── Blank-screen recovery tuning (Task #1491) ─────────────────────
        /** Boot screen / recovery card background — the app's own colour. */
        const val BRAND_COLOR = 0xFF0A4D5C.toInt()
        /** Primary button. */
        const val ACCENT_COLOR = 0xFFE7BF3B.toInt()
        const val ACCENT_TEXT_COLOR = 0xFF0A4D5C.toInt()

        /**
         * A main-frame load that has painted NOTHING by now, with the boot
         * screen still up, is the dead-white-screen state. Deliberately
         * generous: a shop on a bad 2G link must not be thrown a recovery card
         * while the page is genuinely still on its way.
         */
        const val PAINT_TIMEOUT_MS = 12000L
        /** Grace after onPageFinished before the "is this document empty?" probe. */
        const val EMPTY_PROBE_DELAY_MS = 700L
        /** The probe itself must answer within this, or the renderer is wedged. */
        const val PROBE_ANSWER_MS = 6000L
        /** Renderer rebuilds before we stop rebuilding and show the card. */
        const val MAX_RENDERER_REBUILDS = 2
    }

    private lateinit var root: FrameLayout
    private lateinit var bootScreen: View
    private lateinit var web: WebView
    private var filePathCallback: ValueCallback<Array<Uri>>? = null
    private var lastMainFrameUrl: String = START_URL

    // Fullscreen <video> support (ported from pos-app v1.0.3): tutorial videos ka
    // fullscreen button WebView mein tab hi chalta hai jab shell
    // onShowCustomView de. Video ek overlay view ban kar aata hai.
    private var customVideoView: View? = null
    private var customViewCallback: WebChromeClient.CustomViewCallback? = null

    // ── Load health (Task #1491) ───────────────────────────────────────────
    private val ui = Handler(Looper.getMainLooper())
    /** Bumped on every main-frame load; stale timers/probes compare against it. */
    private var loadToken = 0
    /** Current document painted AND proven non-empty. */
    private var loadSucceeded = false
    private var loadInFlight = false
    /** The recovery card (a data: URL) is on screen — not a real page. */
    private var showingRecovery = false
    /** onPageCommitVisible seen for the current load — real pixels. */
    private var painted = false
    /** The probe found real content in the current document. */
    private var contentVerified = false
    /** Activity is going away — every posted callback must stop. */
    private var activityDead = false
    private var lastHttpStatus = 0
    private var rendererRebuilds = 0
    private var resumedOnce = false
    /** Reset-app-data asked for a service-worker/cache purge on the next page. */
    private var pendingStoragePurge = false
    private var watchdog: Runnable? = null
    private var probeDeadline: Runnable? = null

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // The WebView sits UNDER the boot screen so an unpainted page shows the
        // brand + spinner instead of the window's blank background.
        root = FrameLayout(this)
        root.setBackgroundColor(BRAND_COLOR)
        web = createWebView()
        root.addView(
            web,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
        )
        bootScreen = buildBootScreen()
        root.addView(
            bootScreen,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
        )
        setContentView(root)

        // restoreState() brings the history list back but NOT the display data
        // (Android dropped that years ago), so on its own nothing would load or
        // paint and the watchdog would card a perfectly good session. Start the
        // restored page as a normal load instead — the health checks then have
        // real callbacks to work with.
        val restored =
            if (savedInstanceState != null) web.restoreState(savedInstanceState) else null
        val startAt = if (restored != null) {
            web.url?.takeIf { it.startsWith("http") } ?: START_URL
        } else {
            START_URL
        }
        loadMain(startAt)

        // Play-Store-jaisa update check (Task 443) — fail-silent, once per launch.
        UpdateCheck.run(this)

        // FBR push (Task #1283): Android 13+ needs a runtime prompt before
        // notifications can show. Ask once on open — denial is fine (the web
        // app's own screens keep working; push simply stays silent).
        if (Build.VERSION.SDK_INT >= 33 &&
            checkSelfPermission(android.Manifest.permission.POST_NOTIFICATIONS) !=
            android.content.pm.PackageManager.PERMISSION_GRANTED) {
            try {
                requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), REQ_NOTIF)
            } catch (e: Exception) {
                // never block the shell over a permission prompt
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // WebView — built in one place so a dead renderer can be rebuilt (#1491)
    // ══════════════════════════════════════════════════════════════════════
    @SuppressLint("SetJavaScriptEnabled")
    private fun createWebView(): WebView {
        val view = WebView(this)

        with(view.settings) {
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
        CookieManager.getInstance().setAcceptThirdPartyCookies(view, false)

        view.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                return routeUrl(request.url)
            }

            override fun onPageStarted(view: WebView, url: String, favicon: android.graphics.Bitmap?) {
                if (view !== web) {   // a WebView we already replaced — not our state
                    super.onPageStarted(view, url, favicon)
                    return
                }
                if (url.startsWith("http")) {
                    lastMainFrameUrl = url
                    // SECURITY: the recovery card's JS bridge must never be
                    // reachable from a page on the site — drop it on every
                    // first-party navigation, not just when the card is up.
                    removeRecoveryBridge()
                    beginLoad()
                }
                // FBR push (Task #1283): first-party navigation past the
                // login page = logged in → register this device's FCM token
                // (session cookie auth); back on /fbr-pos/login = logged out →
                // clear it. Silent no-op without google-services.json.
                Push.onNavigation(this@MainActivity, url)
                super.onPageStarted(view, url, favicon)
            }

            /** First actual pixels of the main frame — the app is alive. */
            override fun onPageCommitVisible(view: WebView, url: String) {
                // Pixels only count for the document being loaded NOW. A late
                // commit for a superseded page (redirect chain, login POST)
                // must not hide the boot screen or disarm the watchdog for the
                // navigation that replaced it — that would hand the shop the
                // blank screen this whole feature exists to prevent.
                if (isLiveDoc(view, url)) {
                    painted = true
                    hideBootScreen()
                    markLoadHealthy()
                }
                super.onPageCommitVisible(view, url)
            }

            override fun onPageFinished(view: WebView, url: String) {
                super.onPageFinished(view, url)
                if (view !== web) return
                if (!url.startsWith("http")) {
                    // The recovery card itself (data: URL) — nothing to verify.
                    loadInFlight = false
                    hideBootScreen()
                    return
                }
                // A late "finished" for a URL we have already navigated away
                // from (redirect chain, login POST) must not certify — or
                // un-flag — the load that is running now.
                if (!isLiveDoc(view, url)) return
                loadInFlight = false
                if (pendingStoragePurge) {
                    pendingStoragePurge = false
                    purgeSiteStorage()
                    return
                }
                val token = loadToken
                ui.postDelayed({ verifyDocument(token) }, EMPTY_PROBE_DELAY_MS)
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                // Only take over the screen for MAIN-frame failures (not a
                // missing image/beacon) — sub-resource errors must never nuke
                // a working page.
                // Stale main-frame failures (an aborted navigation, a view we
                // replaced) must not bury the page that is loading now. If a
                // failure cannot be matched to the live document the watchdog
                // still catches it a few seconds later.
                if (request.isForMainFrame && isLiveDoc(view, request.url.toString())) {
                    val desc = try { error.description?.toString() ?: "" } catch (e: Exception) { "" }
                    showRecovery("NET ${error.errorCode}" + if (desc.isEmpty()) "" else " $desc")
                }
                super.onReceivedError(view, request, error)
            }

            /**
             * 5xx = the server is broken and its body is a useless "Server
             * Error" page, so recover instead of showing it. A 4xx page
             * (403/404/419) is a REAL panel page the user should read — it is
             * only remembered here so the reason line can name it if the
             * document then turns out blank.
             */
            override fun onReceivedHttpError(
                view: WebView,
                request: WebResourceRequest,
                errorResponse: WebResourceResponse
            ) {
                if (request.isForMainFrame && isLiveDoc(view, request.url.toString())) {
                    lastHttpStatus = errorResponse.statusCode
                    if (lastHttpStatus >= 500) showRecovery("HTTP $lastHttpStatus")
                }
                super.onReceivedHttpError(view, request, errorResponse)
            }

            /**
             * Android killed the WebView's rendering process (low memory /
             * renderer crash). Returning false kills the whole app process and
             * leaves a blank window behind, so build a fresh WebView instead.
             */
            @SuppressLint("NewApi")
            override fun onRenderProcessGone(view: WebView, detail: RenderProcessGoneDetail): Boolean {
                if (view !== web) {
                    // Already-replaced view dying — just let it go.
                    try { view.destroy() } catch (e: Exception) {}
                    return true
                }
                val crashed = try { detail.didCrash() } catch (e: Exception) { false }
                rebuildWebView(if (crashed) "RENDERER CRASH" else "RENDERER KILLED")
                return true
            }
        }

        view.webChromeClient = object : WebChromeClient() {
            // Fullscreen video (ported from pos-app v1.0.3): bina in overrides ke video ka
            // fullscreen button dabane par kuch NAHI hota (WebView default =
            // unsupported). Video view decor par overlay hota hai, system bars
            // chhup jaati hain; back/exit par sab waisa hi wapas.
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
                exitFullscreenVideo()
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

        view.setDownloadListener(DownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
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

        return view
    }

    /** Tear the fullscreen video overlay down (exit, back, or a WebView rebuild). */
    private fun exitFullscreenVideo() {
        val v = customVideoView ?: return
        try { (window.decorView as FrameLayout).removeView(v) } catch (e: Exception) {}
        customVideoView = null
        customViewCallback?.onCustomViewHidden()
        customViewCallback = null
        web.visibility = View.VISIBLE
        @Suppress("DEPRECATION")
        window.decorView.systemUiVisibility = 0
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

    // ══════════════════════════════════════════════════════════════════════
    // Boot screen (Task #1491)
    // ══════════════════════════════════════════════════════════════════════
    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

    private fun buildBootScreen(): View {
        val wrap = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER
            setBackgroundColor(BRAND_COLOR)
            isClickable = true   // swallow taps meant for the page underneath
        }
        wrap.addView(ImageView(this).apply {
            setImageResource(R.drawable.ic_pos)
            layoutParams = LinearLayout.LayoutParams(dp(96), dp(96))
        })
        wrap.addView(ProgressBar(this).apply {
            isIndeterminate = true
            indeterminateTintList = ColorStateList.valueOf(Color.WHITE)
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            ).apply { topMargin = dp(28) }
        })
        wrap.addView(TextView(this).apply {
            text = getString(R.string.boot_loading)
            setTextColor(Color.WHITE)
            textSize = 15f
            gravity = Gravity.CENTER
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.WRAP_CONTENT,
                ViewGroup.LayoutParams.WRAP_CONTENT
            ).apply { topMargin = dp(18) }
        })
        return wrap
    }

    private fun showBootScreen() {
        if (::bootScreen.isInitialized) bootScreen.visibility = View.VISIBLE
    }

    private fun hideBootScreen() {
        if (::bootScreen.isInitialized) bootScreen.visibility = View.GONE
    }

    private fun bootScreenVisible(): Boolean =
        ::bootScreen.isInitialized && bootScreen.visibility == View.VISIBLE

    // ══════════════════════════════════════════════════════════════════════
    // Load health: watchdog + empty-document probe (Task #1491)
    // ══════════════════════════════════════════════════════════════════════
    /** URL minus its #fragment — callbacks do not always quote it identically. */
    private fun stripFragment(u: String): String = u.substringBefore('#')

    /**
     * Is this callback about the document the shell is showing RIGHT NOW?
     * A callback from a WebView that has been replaced, or one for a URL a
     * newer navigation has superseded, must never touch the health state: it
     * could certify a load that never painted, or bury one that works.
     */
    private fun isLiveDoc(view: WebView, url: String): Boolean {
        if (view !== web) return false
        val u = stripFragment(url)
        return u == stripFragment(lastMainFrameUrl) || u == stripFragment(view.url ?: "")
    }

    /** Reset per-load state and (re)arm the paint watchdog. */
    private fun beginLoad() {
        loadToken++
        loadSucceeded = false
        loadInFlight = true
        painted = false
        contentVerified = false
        lastHttpStatus = 0
        showingRecovery = false
        cancelProbeDeadline()
        armWatchdog(loadToken)
    }

    /** Fresh main-frame load with the boot screen up (launch / retry / rebuild). */
    private fun loadMain(url: String) {
        showBootScreen()
        beginLoad()
        web.loadUrl(url)
    }

    private fun armWatchdog(token: Int) {
        cancelWatchdog()
        val r = Runnable {
            if (token != loadToken || loadSucceeded || showingRecovery) return@Runnable
            // Nothing painted AND the boot screen is still covering the window
            // = the dead-white-screen state. A slow navigation ON TOP of a page
            // that already works is left alone (the old page stays readable).
            if (!painted && bootScreenVisible()) {
                try { web.stopLoading() } catch (e: Exception) {}
                showRecovery("TIMEOUT ${PAINT_TIMEOUT_MS / 1000}s")
            }
        }
        watchdog = r
        ui.postDelayed(r, PAINT_TIMEOUT_MS)
    }

    private fun cancelWatchdog() {
        watchdog?.let { ui.removeCallbacks(it) }
        watchdog = null
    }

    private fun cancelProbeDeadline() {
        probeDeadline?.let { ui.removeCallbacks(it) }
        probeDeadline = null
    }

    /**
     * A load counts as healthy only when it BOTH painted and proved to contain
     * something. Half the proof is not enough: a renderer that runs JS but
     * paints nothing is exactly the reported incident.
     */
    private fun markLoadHealthy() {
        if (!painted || !contentVerified) return
        loadSucceeded = true
        rendererRebuilds = 0
        cancelWatchdog()
        cancelProbeDeadline()
        hideBootScreen()
    }

    /**
     * A page can "finish" and still be nothing at all (empty or truncated
     * body) — the exact shape of the reported incident. Ask the document what
     * it actually contains; only a definite "there is nothing here" fails.
     */
    private fun verifyDocument(token: Int) {
        if (activityDead || token != loadToken || showingRecovery) return
        val probe =
            "(function(){try{var b=document.body;if(!b)return '-1';" +
            "return ((b.innerText||'').trim().length)+':'+b.getElementsByTagName('*').length;}" +
            "catch(e){return '-2';}})()"
        // If the renderer is wedged the callback never arrives — that is a
        // failure too, so put a deadline on the answer itself.
        val deadline = Runnable {
            if (activityDead || token != loadToken || loadSucceeded || showingRecovery) return@Runnable
            showRecovery("NO RESPONSE")
        }
        probeDeadline = deadline
        ui.postDelayed(deadline, PROBE_ANSWER_MS)
        try {
            web.evaluateJavascript(probe) { value ->
                if (activityDead || token != loadToken || showingRecovery) return@evaluateJavascript
                cancelProbeDeadline()
                val v = (value ?: "").trim('"', ' ')
                val parts = v.split(":")
                val text = parts.getOrNull(0)?.toIntOrNull()
                val nodes = parts.getOrNull(1)?.toIntOrNull()
                val empty = v == "-1" || (text == 0 && nodes == 0)
                if (empty) {
                    val status = if (lastHttpStatus >= 400) " HTTP $lastHttpStatus" else ""
                    showRecovery("EMPTY PAGE$status")
                } else {
                    // Anything else (including an unreadable answer) counts as
                    // real content — never throw a recovery card on a guess.
                    // The watchdog stays armed until the pixels arrive too.
                    contentVerified = true
                    markLoadHealthy()
                }
            }
        } catch (e: Exception) {
            cancelProbeDeadline()
        }
    }

    /**
     * Throw the dead WebView away and build a new one. The shell keeps working
     * instead of showing a blank window (or being killed by the platform).
     */
    private fun rebuildWebView(reason: String) {
        val url = lastMainFrameUrl.takeIf { it.startsWith("http") } ?: START_URL
        cancelWatchdog()
        cancelProbeDeadline()
        // The dying view may still own a fullscreen overlay or an unanswered
        // file-picker callback — release both before it is destroyed.
        exitFullscreenVideo()
        filePathCallback?.onReceiveValue(null)
        filePathCallback = null
        try { root.removeView(web) } catch (e: Exception) {}
        try { web.destroy() } catch (e: Exception) {}
        web = createWebView()
        root.addView(
            web,
            0,
            FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
            )
        )
        rendererRebuilds++
        if (rendererRebuilds > MAX_RENDERER_REBUILDS) {
            // Rebuilding is not helping — stop looping and be honest.
            showRecovery(reason)
            return
        }
        loadMain(url)
    }

    // ══════════════════════════════════════════════════════════════════════
    // Recovery card (Task #1491) — replaces the old offline-only page
    // ══════════════════════════════════════════════════════════════════════
    /**
     * @param techReason short, UNTRANSLATED support code (HTTP 503, NET -2,
     *        EMPTY PAGE, TIMEOUT 12s, RENDERER CRASH) — a shop reads it out or
     *        photographs it.
     */
    private fun showRecovery(techReason: String) {
        if (showingRecovery) return
        showingRecovery = true
        loadSucceeded = false
        loadInFlight = false
        cancelWatchdog()
        cancelProbeDeadline()
        hideBootScreen()

        val where = try { Uri.parse(lastMainFrameUrl).path ?: "" } catch (e: Exception) { "" }
        val detail = esc("$techReason · v${BuildConfig.VERSION_NAME}" + if (where.isEmpty()) "" else " · $where")
        val brand = String.format("#%06X", 0xFFFFFF and BRAND_COLOR)
        val accent = String.format("#%06X", 0xFFFFFF and ACCENT_COLOR)
        val accentText = String.format("#%06X", 0xFFFFFF and ACCENT_TEXT_COLOR)
        val html = """
            <!doctype html><html dir="auto"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
              body{font-family:sans-serif;background:$brand;color:#fff;display:flex;
                   align-items:center;justify-content:center;min-height:95vh;margin:0;text-align:center}
              .card{padding:24px;max-width:420px}
              h2{margin:0 0 10px;font-size:22px}
              p{margin:0 0 22px;opacity:.9;font-size:15px;line-height:1.7}
              button{display:block;width:100%;box-sizing:border-box;border:0;border-radius:10px;
                     padding:15px 20px;font-size:16px;font-weight:bold}
              .primary{background:$accent;color:$accentText}
              .secondary{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.55);
                     margin-top:12px;font-weight:normal}
              .reason{margin-top:22px;font-size:12px;opacity:.65;word-break:break-word;
                     font-family:monospace;line-height:1.5}
            </style></head><body><div class="card">
              <h2>${esc(getString(R.string.recover_title))}</h2>
              <p>${esc(getString(R.string.recover_body))}</p>
              <button class="primary" onclick="TNApp.retry()">${esc(getString(R.string.recover_retry))}</button>
              <button class="secondary" onclick="TNApp.reset()">${esc(getString(R.string.recover_reset))}</button>
              <div class="reason">${esc(getString(R.string.recover_reason_label))}: $detail</div>
            </div></body></html>
        """.trimIndent()
        web.addJavascriptInterface(RecoveryBridge(), "TNApp")
        web.loadDataWithBaseURL(null, html, "text/html", "utf-8", null)
    }

    private fun removeRecoveryBridge() {
        try { web.removeJavascriptInterface("TNApp") } catch (e: Exception) {}
    }

    private fun esc(s: String): String = s
        .replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")

    inner class RecoveryBridge {
        /** Primary action: load the app's start page again, from scratch. */
        @android.webkit.JavascriptInterface
        fun retry() {
            runOnUiThread {
                removeRecoveryBridge()
                showingRecovery = false
                rendererRebuilds = 0
                loadMain(START_URL)
            }
        }

        /**
         * Secondary action: wipe the WebView's own cache/storage first, so a
         * poisoned local state (stale service worker, half-written cache entry)
         * is fixed without uninstalling. Cookies are deliberately KEPT — the
         * shop stays logged in; the poison is never the session.
         */
        @android.webkit.JavascriptInterface
        fun reset() {
            runOnUiThread {
                removeRecoveryBridge()
                showingRecovery = false
                rendererRebuilds = 0
                try { web.clearCache(true) } catch (e: Exception) {}
                try { web.clearHistory() } catch (e: Exception) {}
                try { web.clearFormData() } catch (e: Exception) {}
                try { WebStorage.getInstance().deleteAllData() } catch (e: Exception) {}
                // Service workers + Cache Storage can only be dropped from a
                // page of that origin, so finish the job on the next load.
                pendingStoragePurge = true
                loadMain(START_URL)
            }
        }
    }

    /**
     * Unregister every service worker and delete every Cache Storage bucket for
     * the site, then load the start page once more. Runs only after the reset
     * button, on a first-party page (same-origin requirement).
     */
    private fun purgeSiteStorage() {
        val js =
            "(function(){try{" +
            "if(navigator.serviceWorker&&navigator.serviceWorker.getRegistrations){" +
            "navigator.serviceWorker.getRegistrations().then(function(rs){" +
            "rs.forEach(function(r){try{r.unregister();}catch(e){}});});}" +
            "if(window.caches&&caches.keys){caches.keys().then(function(ks){" +
            "ks.forEach(function(k){try{caches.delete(k);}catch(e){}});});}" +
            "try{localStorage.clear();sessionStorage.clear();}catch(e){}" +
            "}catch(e){}})()"
        try { web.evaluateJavascript(js, null) } catch (e: Exception) {}
        ui.postDelayed({
            if (!activityDead && !showingRecovery) loadMain(START_URL)
        }, 1200)
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

    /**
     * Closing and reopening the app after a dead load must genuinely fix it
     * (Task #1491): the old shell just restored the same blank screen. A load
     * that already succeeded is never disturbed.
     */
    override fun onResume() {
        super.onResume()
        // `painted` keeps a working page safe even if its content probe was
        // skipped (a redirect finishing late) — only a screen that never
        // painted, or the recovery card itself, is reloaded.
        if (resumedOnce && !loadSucceeded && !loadInFlight && (!painted || showingRecovery)) {
            showingRecovery = false
            rendererRebuilds = 0
            loadMain(START_URL)
        }
        resumedOnce = true
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
        activityDead = true
        cancelWatchdog()
        cancelProbeDeadline()
        ui.removeCallbacksAndMessages(null)   // pending probes / purge reload
        web.destroy()
        super.onDestroy()
    }
}
