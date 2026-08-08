<?php

namespace App\Exceptions;

/**
 * Control-flow exception for atomic plan-cap admission (Task 362): thrown
 * inside a DB::transaction when the recounted allowance is exhausted so the
 * transaction rolls back and the caller returns a 403 plan_limit response.
 */
class PlanLimitReachedException extends \RuntimeException
{
}
