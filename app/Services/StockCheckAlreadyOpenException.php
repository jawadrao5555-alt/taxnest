<?php

namespace App\Services;

/**
 * Raised when a branch already has a count sheet open.
 *
 * Two open sheets on the same branch each freeze their own "should be"
 * snapshot; posting both would apply the same shortage twice. The UI hides the
 * button, but the rule has to live where the row is written.
 */
class StockCheckAlreadyOpenException extends \RuntimeException
{
}
