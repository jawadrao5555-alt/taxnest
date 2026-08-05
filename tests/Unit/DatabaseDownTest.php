<?php

namespace Tests\Unit;

use App\Support\DatabaseDown;
use PHPUnit\Framework\TestCase;

class DatabaseDownTest extends TestCase
{
    private function pdoException(string $message, ?int $driverCode = null, mixed $code = 'HY000'): \PDOException
    {
        // PDOException::$code is protected — set it the way PHP does, via an
        // anonymous subclass (real connect failures carry e.g. int 2002 here).
        $e = new class($message, $code) extends \PDOException {
            public function __construct(string $message, mixed $code)
            {
                parent::__construct($message);
                $this->code = $code;
            }
        };
        if ($driverCode !== null) {
            $e->errorInfo = ['HY000', $driverCode, $message];
        }
        return $e;
    }

    public function test_connection_refused_is_connection_failure(): void
    {
        $e = $this->pdoException('SQLSTATE[HY000] [2002] Connection refused', null, 2002);
        $this->assertTrue(DatabaseDown::isConnectionFailure($e));
    }

    public function test_wrapped_query_exception_chain_is_detected(): void
    {
        $pdo = $this->pdoException('SQLSTATE[HY000] [2002] Connection timed out', null, 2002);
        $wrapper = new \RuntimeException('SQLSTATE[HY000] [2002] Connection timed out (Connection: mysql, SQL: select * from companies)', 0, $pdo);
        $this->assertTrue(DatabaseDown::isConnectionFailure($wrapper));
    }

    public function test_server_gone_away_and_too_many_connections(): void
    {
        $this->assertTrue(DatabaseDown::isConnectionFailure(
            $this->pdoException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 2006)
        ));
        $this->assertTrue(DatabaseDown::isConnectionFailure(
            $this->pdoException('SQLSTATE[HY000] [1040] Too many connections', 1040)
        ));
        $this->assertTrue(DatabaseDown::isConnectionFailure(
            $this->pdoException("SQLSTATE[42000] [1226] User 'x' has exceeded the 'max_user_connections' resource", 1226)
        ));
    }

    public function test_ordinary_query_errors_are_NOT_connection_failures(): void
    {
        // Missing column — must keep default 500 handling
        $this->assertFalse(DatabaseDown::isConnectionFailure(
            $this->pdoException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'foo' in 'field list'", 1054)
        ));
        // Duplicate key
        $this->assertFalse(DatabaseDown::isConnectionFailure(
            $this->pdoException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x'", 1062)
        ));
        // Syntax error
        $this->assertFalse(DatabaseDown::isConnectionFailure(
            $this->pdoException('SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax', 1064)
        ));
        // Non-DB exception
        $this->assertFalse(DatabaseDown::isConnectionFailure(new \RuntimeException('something else broke')));
    }
}
