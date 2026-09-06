<?php

namespace App\Services\Pharmacy;

use Illuminate\Support\Facades\Http;

/**
 * Reads DRAP's public Pharmaceutical Product Price Index (Task 1579).
 *
 * Source: Drug Regulatory Authority of Pakistan — https://e.dra.gov.pk/public/price
 * (Government of Pakistan public data). The listing paginates with ?page=N,
 * 20 rows per page; each row carries brand + composition, registration number,
 * manufacturer + licence, category badge, pack text, MRP in Rs and the
 * effective-from date.
 *
 * Politeness is the caller's job (sequential pages, a delay between them);
 * this class only fetches ONE page with a couple of retries on transient
 * 5xx/timeouts, and parses HTML into plain arrays. The parser is static so
 * tests feed it a saved page without any network.
 */
class DrapPriceIndexClient
{
    public const BASE_URL = 'https://e.dra.gov.pk/public/price';

    public const ROWS_PER_PAGE = 20;

    /** Seconds between pages — 1,070 pages at ~2.5s fetch + this ≈ 1 hour. */
    public const PAGE_DELAY_SECONDS = 1.0;

    /**
     * @param  array<string,string>  $filters  e.g. ['category' => 'Low Price Drugs']
     * @return array{page:int,total_pages:?int,rows:array<int,array<string,mixed>>}
     */
    public function fetchPage(int $page, array $filters = []): array
    {
        $query = array_merge($filters, ['page' => $page]);
        $attempt = 0;
        $lastError = null;
        while ($attempt < 3) {
            $attempt++;
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; TaxNestCatalogue/1.0; +https://taxnest.pk)',
                    'Accept' => 'text/html',
                ])->timeout(40)->connectTimeout(15)->get(self::BASE_URL, $query);

                if ($response->successful()) {
                    $parsed = self::parseHtml($response->body());
                    if ($parsed['total_pages'] === null && empty($parsed['rows'])) {
                        // A 200 with no table = WAF/maintenance page. Treat as transient.
                        throw new \RuntimeException('DRAP page ' . $page . ' returned no price table');
                    }
                    $parsed['page'] = $page;

                    return $parsed;
                }
                $lastError = 'HTTP ' . $response->status();
                if ($response->status() < 500 && $response->status() !== 429) {
                    break; // 4xx other than rate-limit will not heal by retrying
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
            usleep((int) (1_500_000 * $attempt));
        }

        throw new \RuntimeException('DRAP fetch failed for page ' . $page . ': ' . ($lastError ?? 'unknown'));
    }

    /**
     * Parse one listing page. Pure function — no network.
     *
     * @return array{page:int,total_pages:?int,rows:array<int,array<string,mixed>>}
     */
    public static function parseHtml(string $html): array
    {
        $totalPages = null;
        if (preg_match('/Showing page\s*<span[^>]*>\s*(\d+)\s*<\/span>\s*of\s*<span[^>]*>\s*(\d+)\s*<\/span>/is', $html, $m)) {
            $totalPages = (int) $m[2];
        }

        $rows = [];
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('//table//tbody/tr') as $tr) {
            $tds = $xpath->query('./td', $tr);
            if ($tds->length < 7) {
                continue;
            }
            $cell = fn (int $i) => $tds->item($i);
            $text = fn (?\DOMNode $n) => $n ? self::tidy($n->textContent) : '';

            // td1: brand (first div with text) + composition (second)
            $divs1 = $xpath->query('.//div[contains(@class,"ml-4")]/div', $cell(0));
            $brand = $text($divs1->item(0));
            $composition = $text($divs1->item(1));
            if ($brand === '') {
                // Layout drift fallback: first non-empty line of the cell.
                $lines = array_values(array_filter(array_map([self::class, 'tidy'], preg_split('/\n+/', $cell(0)->textContent))));
                $brand = $lines[0] ?? '';
                $composition = $lines[1] ?? $composition;
            }

            $regNo = $text($cell(1));

            $divs3 = $xpath->query('./div', $cell(2));
            $manufacturer = $text($divs3->item(0));
            $licence = $text($divs3->item(1));
            if ($manufacturer === '') {
                $manufacturer = $text($cell(2));
            }

            $categoryLabel = $text($cell(3));
            $pack = $text($cell(4));

            $priceText = $text($cell(5));
            $priceText = preg_replace('/^rs\.?\s*/i', '', $priceText);
            $mrp = null;
            if (preg_match('/-?[\d,]+(?:\.\d+)?/', $priceText, $pm)) {
                $mrp = (float) str_replace(',', '', $pm[0]);
            }

            $dateText = $text($cell(6));
            $effective = null;
            if ($dateText !== '') {
                try {
                    $effective = \Carbon\Carbon::createFromFormat('d M, Y', $dateText)->format('Y-m-d');
                } catch (\Throwable $e) {
                    $ts = strtotime($dateText);
                    $effective = $ts ? date('Y-m-d', $ts) : null;
                }
            }

            if ($brand === '' && $regNo === '') {
                continue;
            }

            $rows[] = [
                'brand_name' => $brand,
                'composition' => $composition,
                'drap_reg_no' => $regNo,
                'manufacturer' => $manufacturer,
                'manufacturer_licence' => $licence,
                'category_label' => $categoryLabel,
                'pack_size' => $pack,
                'mrp' => $mrp,
                'effective_date' => $effective,
            ];
        }

        return ['page' => 0, 'total_pages' => $totalPages, 'rows' => $rows];
    }

    private static function tidy(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x{00A0}\s]+/u', ' ', $s);

        return trim((string) $s);
    }
}
