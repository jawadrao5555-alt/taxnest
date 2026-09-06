<?php

namespace App\Services\Pharmacy;

use App\Models\Product;

/**
 * Heuristic parser: DRAP brand + composition text → generic name, strength,
 * dosage form (Task 1579).
 *
 * DRAP publishes "Panadol Tablets 500mg" / "Paracetamol 500mg" style text with
 * no structured columns. The shop needs a salt name to search on and a form to
 * sell loose units by, so we derive them here — best effort, editable later,
 * and NEVER a reason to refuse a row (parse() cannot throw; unknowns are null).
 *
 * Examples
 *   "Paracetamol 500mg"                              → Paracetamol / 500mg
 *   "Amoxicillin 500mg + Clavulanic Acid 125mg"      → Amoxicillin + Clavulanic Acid / 500mg + 125mg
 *   "Each 5ml contains: Ibuprofen 100mg"             → Ibuprofen / 100mg/5ml
 *   brand "Brufen Suspension"                        → dosage_form suspension
 */
class MedicineCompositionParser
{
    /** Dosage-form keywords → Product::DOSAGE_FORMS value. Order matters (first hit wins). */
    private const FORM_KEYWORDS = [
        'injection' => ['injection', 'inj.', ' inj ', 'injectable', 'infusion', 'i.v.', 'i/v', 'i.m.', 'vial', 'ampoule', 'ampule', 'prefilled syringe', 'pre-filled'],
        'suspension' => ['suspension', 'susp.', ' susp', 'dry powder for suspension', 'oral susp'],
        'syrup' => ['syrup', 'elixir', 'linctus', 'oral solution', 'oral liquid', 'solution for oral'],
        'drops' => ['drops', 'eye drop', 'ear drop', 'nasal drop', 'ophthalmic solution', 'otic'],
        'inhaler' => ['inhaler', 'inhalation', 'nebul', 'respule', 'rotacap', 'evohaler', 'turbuhaler'],
        'cream' => ['cream', 'lotion', 'emulsion', 'topical'],
        'ointment' => ['ointment', 'oint.', 'gel', 'paste'],
        'sachet' => ['sachet', 'powder for oral', 'oral powder', 'granules', 'effervescent'],
        'suppository' => ['suppository', 'suppositories', 'pessary', 'pessaries', 'rectal'],
        'capsule' => ['capsule', 'capsules', 'caps.', ' caps', ' cap ', 'softgel', 'soft gel'],
        'tablet' => ['tablet', 'tablets', 'tabs.', ' tabs', ' tab ', 'caplet', 'chewable', 'dispersible', 'lozenge', 'orodispersible', 'film coated', 'f.c.', 'sr ', 'xr ', 'cr '],
    ];

    /** Strength token: 500mg, 2.5 mg, 100mg/5ml, 1g, 250mcg, 5%, 10 IU, 1000000 units, 20mEq. */
    private const STRENGTH_RE = '/(\d+(?:[.,]\d+)?)\s*(mcg|µg|ug|mg|gm|g|kg|iu|i\.u\.|units?|u|meq|mmol|ml|%|w\/v|w\/w|v\/v)(?:\s*\/\s*(\d+(?:[.,]\d+)?)?\s*(ml|g|gm|mg|l|dose|actuation|puff|drop|tablet|tab|capsule|cap|sachet|vial|ampoule|amp|spray|application))?/iu';

    /**
     * @return array{generic_name: ?string, strength: ?string, dosage_form: ?string}
     */
    public function parse(?string $brand, ?string $composition, ?string $packSize = null): array
    {
        try {
            $comp = $this->clean((string) $composition);
            $brandText = $this->clean((string) $brand);

            $form = $this->detectForm($brandText . ' ' . $comp . ' ' . $this->clean((string) $packSize));

            [$generic, $strength] = $this->splitComposition($comp);

            // Composition carried no strength (e.g. "Paracetamol") — try the
            // brand text ("Panadol Extra 500mg").
            if ($strength === null) {
                $strength = $this->strengthFrom($brandText);
            }

            return [
                'generic_name' => $generic !== '' ? mb_substr($generic, 0, 250) : null,
                'strength' => $strength !== null && $strength !== '' ? mb_substr($strength, 0, 110) : null,
                'dosage_form' => $form,
            ];
        } catch (\Throwable $e) {
            return ['generic_name' => null, 'strength' => null, 'dosage_form' => null];
        }
    }

    public function detectForm(string $text): ?string
    {
        $t = ' ' . mb_strtolower($text) . ' ';
        foreach (self::FORM_KEYWORDS as $form => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($t, $kw)) {
                    return in_array($form, Product::DOSAGE_FORMS, true) ? $form : 'other';
                }
            }
        }

        return null;
    }

    /**
     * "Amoxicillin 500mg + Clavulanic Acid 125mg" → ["Amoxicillin + Clavulanic Acid", "500mg + 125mg"].
     *
     * @return array{0: string, 1: ?string}
     */
    private function splitComposition(string $comp): array
    {
        if ($comp === '') {
            return ['', null];
        }

        // "Each 5ml contains:" / "Each tablet contains" — remember the per-unit
        // basis so a syrup keeps its "100mg/5ml" meaning, then strip the prefix.
        $perUnit = null;
        $prefixRe = '/^\s*(?:each|every|per)\b(.{0,90}?)(?:\b(?:contains?|containing|has)\b\s*:?|:)\s*/iu';
        if (preg_match($prefixRe, $comp, $m)) {
            $basis = mb_strtolower($m[1]);
            // "0.5ml vial" → per 0.5ml; "tablet" → per tablet; "single 0.5ml dose" → per 0.5ml.
            if (preg_match('/(\d+(?:[.,]\d+)?)\s*(ml|g|gm|mg|l)\b/iu', $basis, $bm)) {
                $perUnit = str_replace(',', '.', $bm[1]) . $this->normaliseUnit($bm[2]);
            } elseif (preg_match('/\b(tablet|tab|capsule|cap|sachet|vial|ampoule|amp|drop|puff|actuation|dose|spray|softgel|suppository|lozenge|pessary)\b/iu', $basis, $bm)) {
                $perUnit = $this->normaliseUnit($bm[1]);
            }
            $comp = trim(mb_substr($comp, mb_strlen($m[0])));
            $comp = ltrim($comp, ':- ');
        }

        // Parentheticals: keep the content when the salt lives inside it
        // ("( Azithromycin as dihydrate 500mg)"), drop pure notes ("(as per SRO)").
        $comp = preg_replace_callback('/\(([^()]*)\)/u', function ($pm) {
            return preg_match(self::STRENGTH_RE, $pm[1]) ? ' ' . $pm[1] . ' ' : ' ';
        }, $comp);

        // Multi-salt separators: "+", ";", " & ", " and ", "," (not thousands commas).
        $parts = preg_split('/\s*(?:\+|;|&|\band\b|,(?!\d{3}\b))\s*/iu', $comp) ?: [$comp];
        $names = [];
        $strengths = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $s = $this->strengthFrom($part);
            $name = trim(preg_replace(self::STRENGTH_RE, ' ', $part));
            // Leftovers: "as trihydrate ...", "eq. to ...", pharmacopoeia tags, stray "x".
            $name = preg_replace('/\b(?:eq\.?|equivalent|equiv\.?)\s*(?:to)?\b.*$/iu', ' ', $name);
            $name = preg_replace('/\s+as\s+.*$/iu', ' ', $name);
            $name = preg_replace('/\b(?:usp|bp|ip|jp|ph\.?\s*eur\.?|specs?|spces)\b.*$/iu', ' ', $name);
            $name = preg_replace('/\b(?:base|anhydrous|monohydrate|trihydrate|dihydrate|hemihydrate|sodium|potassium|hydrochloride|hcl|sulphate|sulfate|maleate|citrate|acetate|tartrate|mesylate|besylate|phosphate|succinate|fumarate|bromide|nitrate|calcium|magnesium|micronized|micronised)\b\s*$/iu', '', $name);
            $name = preg_replace('/\s+\b(?:x|in|per|of)\b\s*$/iu', '', $name);
            $name = trim(preg_replace('/[\s\-:.,\/]+$/u', '', preg_replace('/\s+/', ' ', $name)));
            $name = trim(preg_replace('/^[\s\-:.,\/]+/u', '', $name));
            if ($name !== '') {
                $names[] = $this->titleCase($name);
            }
            if ($s !== null) {
                $strengths[] = $s;
            }
        }

        $generic = implode(' + ', array_values(array_unique($names)));
        $strength = $strengths ? implode(' + ', $strengths) : null;
        if ($strength !== null && $perUnit !== null && !str_contains($strength, '/')) {
            $strength .= '/' . $perUnit;
        }

        return [$generic, $strength];
    }

    /** First strength token in the text, normalised ("500 mg" → "500mg", "100mg / 5 ml" → "100mg/5ml"). */
    public function strengthFrom(string $text): ?string
    {
        if (!preg_match(self::STRENGTH_RE, $text, $m)) {
            return null;
        }
        $num = str_replace(',', '.', $m[1]);
        $unit = $this->normaliseUnit($m[2]);
        $out = $num . $unit;
        if (!empty($m[4])) {
            $out .= '/' . (isset($m[3]) && $m[3] !== '' ? str_replace(',', '.', $m[3]) : '') . $this->normaliseUnit($m[4]);
        }

        return $out;
    }

    private function normaliseUnit(string $u): string
    {
        $u = mb_strtolower(trim($u));

        return match ($u) {
            'µg', 'ug' => 'mcg',
            'gm' => 'g',
            'i.u.' => 'IU',
            'iu' => 'IU',
            'unit', 'units', 'u' => 'units',
            'meq' => 'mEq',
            'amp' => 'ampoule',
            'tab' => 'tablet',
            'cap' => 'capsule',
            default => $u,
        };
    }

    private function clean(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x{00A0}\s]+/u', ' ', $s);

        return trim((string) $s);
    }

    private function titleCase(string $s): string
    {
        // Keep ALL-CAPS DRAP text readable ("PARACETAMOL" → "Paracetamol") but
        // leave mixed-case names (e.g. "Co-Amoxiclav", "Vitamin B12") alone.
        if ($s === mb_strtoupper($s) && mb_strlen($s) > 3) {
            return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
        }

        return $s;
    }
}
