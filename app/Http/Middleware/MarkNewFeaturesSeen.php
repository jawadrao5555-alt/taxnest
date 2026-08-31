<?php

namespace App\Http\Middleware;

use App\Services\NewFeatureBadges;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * "NEW" nishan ki yaadasht — ZFC feedback, 1 Sep 2026.
 *
 * Shikayat: "NEW bar bar aa raha hai." Nishan sirf tareekh se chalta tha, is
 * liye window ke poore arse mein woh har page load par chamakta rehta — chahe
 * shop us switch tak ja bhi chuki ho.
 *
 * Nishan ka maqsad SIRF raasta dikhana hai: "yeh nayi cheez yahan baithi hai."
 * Jab shop us page tak pohanch gayi, maqsad poora ho gaya. Is liye "dekh liya"
 * ka waqia = us page ka khulna, koi alag "band karo" button nahi (jise dabana
 * bhi ek kaam hota, aur cashier usay khud kabhi na dabata).
 *
 * Yaadasht cookie mein hai, database mein nahi:
 *  • naya column PROD par drift karta hai (memory: prod-schema-drift-selfheal)
 *    aur yeh mehez cosmetic cheez kisi settings page ko tor nahi sakti;
 *  • per-DEVICE hone se counter ke doosre computer/staff ko nishan ab bhi
 *    milta hai — jo is feature ka asal maqsad tha.
 *
 * Ehtiyat: sirf kaamyab GET HTML page par likhte hain. POST/redirect/JSON/
 * download par likhna aik aisa page "dekha hua" bana deta jo kabhi khula hi
 * nahi.
 */
class MarkNewFeaturesSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (!$request->isMethod('GET') || $request->ajax() || $request->wantsJson()) {
                return $response;
            }
            if ($response->getStatusCode() !== 200) {
                return $response;
            }
            $contentType = (string) $response->headers->get('Content-Type', '');
            if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
                return $response;
            }

            // keysForRoute pehle hi "dekhe hue" nikal chuka hota hai, is liye
            // khali list = kuch naya nahi likhna (har request par cookie dobara
            // set karne se bacha).
            $fresh = NewFeatureBadges::keysForRoute($request->route()?->getName());
            if ($fresh === []) {
                return $response;
            }

            $all = array_values(array_unique(array_merge(NewFeatureBadges::seenKeys(), $fresh)));
            // Bandish: kharab/bara cookie kabhi header limit na toray.
            if (count($all) > 60) {
                $all = array_slice($all, -60);
            }

            $response->headers->setCookie(
                Cookie::make(
                    NewFeatureBadges::SEEN_COOKIE,
                    implode(',', $all),
                    NewFeatureBadges::SEEN_COOKIE_DAYS * 24 * 60
                )
            );
        } catch (\Throwable $e) {
            // Cosmetic feature — kisi bhi surat mein page na roke.
        }

        return $response;
    }
}
