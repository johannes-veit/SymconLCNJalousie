<?php

declare(strict_types=1);

// Minimal SDK stub: the test exercises only the pure private relay-state guard.
class IPSModuleStrict
{
}

require_once __DIR__ . '/../LCNJalousie/module.php';

$module = new LCNJalousie();
$method = new ReflectionMethod(LCNJalousie::class, 'relayCommandStillRequired');
$method->setAccessible(true);

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
};

// Start is allowed only while both configured relay variables are OFF.
$assertSame(true, $method->invoke($module, 0, 0, 1, 'test'), 'AUF start from OFF');
$assertSame(true, $method->invoke($module, 0, 0, 2, 'test'), 'ZU start from OFF');

// STOP is emitted only while exactly its real direction is active.
$assertSame(true, $method->invoke($module, 1, 1, 1, 'test'), 'AUF stop while AUF active');
$assertSame(true, $method->invoke($module, 2, 2, 2, 'test'), 'ZU stop while ZU active');

// If the relay became OFF before transmission, the STOP is already fulfilled:
// returning false means "do not send another toggle".
$assertSame(false, $method->invoke($module, 0, 1, 1, 'test'), 'AUF stop already OFF');
$assertSame(false, $method->invoke($module, 0, 2, 2, 'test'), 'ZU stop already OFF');

$mustThrow = static function (callable $call, string $message): void {
    try {
        $call();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message . ': expected RuntimeException');
};

$mustThrow(fn () => $method->invoke($module, 1, 0, 1, 'test'), 'Start must not toggle active AUF relay');
$mustThrow(fn () => $method->invoke($module, 2, 0, 2, 'test'), 'Start must not toggle active ZU relay');
$mustThrow(fn () => $method->invoke($module, 2, 1, 1, 'test'), 'AUF stop with ZU active');
$mustThrow(fn () => $method->invoke($module, 1, 2, 2, 'test'), 'ZU stop with AUF active');
$mustThrow(fn () => $method->invoke($module, 3, 1, 1, 'test'), 'Both relays active');

echo "RELAY COMMAND STATE TEST OK\n";
