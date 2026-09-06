<?php

namespace App\Exceptions;

/**
 * An entry that arrived after its period was shut.
 *
 * The check that a period is open is worthless if it happens before the write
 * it protects: two clicks apart, an accountant can close November while a bill
 * posted a moment earlier is still on its way to the ledger, and November's
 * frozen statement would then be missing an entry that sits inside November
 * forever. This exception exists so the check can live INSIDE the writing
 * transaction, under the same lock the close takes, and unwind the write if the
 * door shut in between.
 */
class FiscalPeriodClosed extends \RuntimeException
{
}
