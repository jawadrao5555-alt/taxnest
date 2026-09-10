<?php

namespace App\Exceptions;

/**
 * Fail-closed company name resolution. Never pick a tenant when matches > 1.
 */
class LiveOpsCompanyResolutionException extends \InvalidArgumentException
{
    public const NOT_FOUND = 'not_found';

    public const AMBIGUOUS = 'ambiguous';

    public const INVALID = 'invalid';

    /**
     * @param  list<array{id:int,name:string,account_code:?string}>  $matches
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $matches = [],
        public readonly string $query = '',
    ) {
        parent::__construct($message);
    }

    public function ownerMessage(): string
    {
        if ($this->reason === self::AMBIGUOUS) {
            $names = array_values(array_unique(array_map(
                fn (array $m) => (string) ($m['name'] ?? ''),
                $this->matches
            )));

            return 'Multiple companies match "'.$this->query.'": '.implode(', ', $names).'. Name the exact company.';
        }
        if ($this->reason === self::NOT_FOUND) {
            return 'No NestPOS company named "'.$this->query.'" was found.';
        }

        return $this->getMessage();
    }
}
