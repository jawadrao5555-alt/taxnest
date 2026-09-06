<?php

namespace App\Exceptions;

/**
 * An opening balance that could not be put in the books.
 *
 * Carries no behaviour — it exists so the account save that goes with it can be
 * rolled back and the reason shown on the form, instead of the row being kept
 * with a figure the ledger never accepted.
 */
class OpeningBalanceRefused extends \RuntimeException
{
}
