<?php
declare(strict_types=1);
spl_autoload_register(static function (string $name): void {
    $prefix = 'Ubcc\\V2\\';
    if (str_starts_with($name, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
    }
});
use Ubcc\V2\Domain\Lifecycle\{Machine,Snapshot,Intent,Phase,FailureCode};
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
$a = Snapshot::initial();
check($a->phase === Phase::Stopped && $a->intent === Intent::Off, 'initial state is stopped');
$a = Machine::request($a, Intent::Bot);
check($a->phase === Phase::Queued && $a->generation === 1, 'start records bot intent');
check(Machine::request($a, Intent::Bot) === $a, 'duplicate start is idempotent');
$run1 = str_repeat('a', 32);
$a = Machine::beginAttempt($a, $run1, 100);
check($a->phase === Phase::Starting && $a->attempts === 1, 'attempt begins');
$a = Machine::ready($a, 1, $run1, 101);
check($a->phase === Phase::Running && $a->heartbeatAt === 101, 'ready is explicit');
check(Machine::ready($a, 0, $run1, 102) === $a, 'old generation cannot mark ready');
$a = Machine::failed($a, 1, $run1, FailureCode::ProcessExit, 110);
check($a->phase === Phase::Failed && $a->retryAt === 120, 'worker crash has bounded retry');
check(Machine::due($a, 119) === $a, 'retry cannot run before deadline');
$a = Machine::due($a, 120);
check($a->phase === Phase::Retrying, 'retry becomes due');
$run2 = str_repeat('b', 32);
$a = Machine::beginAttempt($a, $run2, 120);
check($a->attempts === 2 && $a->runId === $run2, 'new attempt has new run ID');
check(Machine::heartbeat($a, 1, $run1, 121) === $a, 'old heartbeat is fenced');
$a = Machine::ready($a, 1, $run2, 122);
check($a->phase === Phase::Running, 'recovered run becomes ready');
$b = Machine::request($a, Intent::Qr);
check($b->intent === Intent::Qr && $b->generation === 2 && $b->phase === Phase::Queued, 'QR request fences old bot generation');
check(Machine::failed($b, 1, $run2, FailureCode::ProcessExit, 123) === $b, 'obsolete bot cannot fail QR generation');
$c = Machine::stop($a);
check($c->intent === Intent::Off && $c->generation === 2, 'stop fences old run');
check(Machine::ready($c, 1, $run2, 124) === $c, 'late readiness cannot undo stop');
check(Machine::stopped($c)->phase === Phase::Stopped, 'stop acknowledged');
$d = Machine::request(Snapshot::initial(), Intent::Bot);
$d = Machine::beginAttempt($d, str_repeat('c', 32), 130);
$d = Machine::failed($d, 1, str_repeat('c', 32), FailureCode::SpawnFailure, 131);
check($d->retryAt === null, 'permanent spawn failure does not retry');
echo "$checks v2 lifecycle domain checks passed\n";
