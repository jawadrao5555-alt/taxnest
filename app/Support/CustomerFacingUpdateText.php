<?php

namespace App\Support;

/** Plain display copy for a published legacy notice; stored history stays intact. */
final class CustomerFacingUpdateText
{
    public static function points(array $points): array
    {
        return array_map(static fn ($point) => $point === 'Build date packaging se aye gi; metadata na ho to unavailable bataya jaye ga.'
            ? 'Agent banne ki tareekh Settings mein nazar aayegi. Purane Agent mein yeh tareekh na mile to "Build date available nahi" likha hoga.'
            : $point, $points);
    }
}
