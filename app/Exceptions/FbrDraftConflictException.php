<?php

namespace App\Exceptions;

/**
 * Task 1271 — thrown INSIDE FbrPosController::store()'s DB transaction when
 * the sale carries a draft_id but the atomic consume-DELETE claimed 0 rows:
 * either the draft was already consumed by a competing settlement (row gone)
 * or another cashier holds a fresh edit lock. Rolls the whole sale back —
 * a lost draft claim must NEVER create a second fiscal transaction.
 */
class FbrDraftConflictException extends \RuntimeException
{
    public function __construct(public readonly int $draftId)
    {
        parent::__construct("FBR draft {$draftId} settlement claim lost");
    }
}
