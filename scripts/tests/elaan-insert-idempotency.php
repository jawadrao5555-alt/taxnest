<?php
/**
 * Behavioral model of scripts/elaan-insert.sh title idempotency.
 * Does not connect to live MySQL. The companion shell test greps the
 * real helper so this cannot drift from the streamed PHP.
 */
declare(strict_types=1);

const RESERVED_L001 = 'Daily L001 ke liye roz Reset dabana zaroori nahi';

/**
 * @param array<int, array{id:int,title:string,created_at:string}> $store
 * @return array{rc:int, op:string, id:?int}
 */
function elaan_insert_once(array &$store, string $title, string $now): array
{
    if ($title === RESERVED_L001) {
        return ['rc' => 1, 'op' => 'fail_reserved', 'id' => null];
    }
    foreach ($store as $row) {
        if ($row['title'] === $title) {
            return ['rc' => 0, 'op' => 'ELAAN_EXISTS', 'id' => $row['id']];
        }
    }
    $id = $store === [] ? 1 : (max(array_column($store, 'id')) + 1);
    $store[] = ['id' => $id, 'title' => $title, 'created_at' => $now];
    return ['rc' => 0, 'op' => 'ELAAN_INSERTED', 'id' => $id];
}

function assert_true(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "PASS: $msg\n";
}

$store = [];
$title = "What's New ab approved production deploy ke saath aata hai";

$first = elaan_insert_once($store, $title, '2026-09-07 14:17:05');
assert_true($first['rc'] === 0 && $first['op'] === 'ELAAN_INSERTED' && $first['id'] === 1, 'first insert succeeds');
assert_true(count($store) === 1, 'first insert created exactly one row');
$createdAt = $store[0]['created_at'];
$id = $store[0]['id'];

$second = elaan_insert_once($store, $title, '2026-09-08 18:00:00');
assert_true($second['rc'] === 0 && $second['op'] === 'ELAAN_EXISTS' && $second['id'] === $id, 'existing exact title is a successful no-op');
assert_true(count($store) === 1, 'no duplicate is created');
assert_true($store[0]['created_at'] === $createdAt && $store[0]['created_at'] === '2026-09-07 14:17:05', 'existing row is not re-dated');
assert_true($second['rc'] === 0, 'deployment can proceed after the no-op (insert helper exit 0)');

$l001 = elaan_insert_once($store, RESERVED_L001, '2026-09-08 18:00:00');
assert_true($l001['rc'] === 1 && $l001['op'] === 'fail_reserved', 'Daily L001 title is rejected');
assert_true(count($store) === 1 && $store[0]['title'] === $title, 'Daily L001 reject does not insert or mutate the existing row');

echo "ALL PHP IDEMPOTENCY CHECKS PASSED\n";
exit(0);
