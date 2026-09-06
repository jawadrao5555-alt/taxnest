<?php

namespace App\Exceptions;

/**
 * A ledger reversal the books would not accept.
 *
 * Undoing something financial is two writes: the operational row is stamped
 * reversed AND its journal is mirrored out. Doing the first without the second
 * is the worst outcome available — the screen says the money came back and the
 * ledger says it never left. This exception exists so the stamping can be
 * rolled back with the refusal, and the reason shown to whoever pressed the
 * button.
 */
class LedgerReversalRefused extends \RuntimeException
{
}
