<?php

if (getenv('RC_SAFE_RUN') !== '1' || !in_array($argv[1] ?? '', ['seed', 'claim', 'verify'], true)) exit(2);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = (string) config('database.connections.mysql.database');
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_claims$/D', $name)
    || !in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) exit(2);
config(['database.default' => 'mysql']);
\Illuminate\Support\Facades\DB::purge('mysql');
$db = \Illuminate\Support\Facades\DB::connection();
$schema = $db->getSchemaBuilder();
if ($argv[1] === 'seed') {
    $schema->create('fbr_pos_transactions', function ($t) {
        $t->id(); $t->unsignedBigInteger('company_id'); $t->string('fbr_status');
        $t->string('invoice_mode')->nullable(); $t->string('fbr_invoice_number')->nullable();
        $t->string('fbr_submission_hash')->nullable(); $t->timestamp('fbr_agent_claimed_at')->nullable();
        $t->boolean('is_archived')->default(false); $t->timestamps();
    });
    $db->table('fbr_pos_transactions')->insert([
        ['id' => 91, 'company_id' => 91, 'fbr_status' => 'pending', 'invoice_mode' => 'fbr'],
        ['id' => 92, 'company_id' => 92, 'fbr_status' => 'pending', 'invoice_mode' => 'fbr'],
    ]);
} elseif ($argv[1] === 'claim') {
    $bill = \App\Models\FbrPosTransaction::findOrFail(91);
    echo \App\Services\FbrPosAgentClaim::acquire($bill) ? "won\n" : "lost\n";
} else {
    $bill = \App\Models\FbrPosTransaction::findOrFail(91);
    if (!\App\Services\FbrPosAgentClaim::active($bill)) throw new RuntimeException('No exclusive claim recorded');
    if (\App\Services\FbrPosAgentClaim::prepareRetry($bill)) throw new RuntimeException('Retry cleared an active claim');
    $db->table('fbr_pos_transactions')->where('id', 91)->update(['fbr_agent_claimed_at' => now()->subMinutes(4)]);
    \App\Services\FbrPosAgentClaim::quarantineExpired(91);
    if ($bill->fresh()->fbr_status !== 'verification_pending') throw new RuntimeException('Expired claim was not held');
    if (\App\Services\FbrPosAgentClaim::acquire($bill->fresh())) throw new RuntimeException('Expired claim redispatched');
    if (\App\Models\FbrPosTransaction::findOrFail(92)->fbr_status !== 'pending') throw new RuntimeException('Other company was changed');
    echo "PASS: exclusive native claim, retry refusal, expiry quarantine and tenant isolation\n";
}
