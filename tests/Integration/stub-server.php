<?php

declare(strict_types=1);

/**
 * A minimal stand-in for the Tiden API, run under PHP's built-in server by
 * ParaTestCoordinationTest. It appends one line per request to TIDEN_STUB_LOG
 * so the test can count how many runs were created and completed across
 * several concurrent PHPUnit processes.
 */
$log = getenv('TIDEN_STUB_LOG');
$path = $_SERVER['REQUEST_URI'] ?? '';
$body = file_get_contents('php://input') ?: '{}';

if (is_string($log) && $log !== '') {
    $handle = fopen($log, 'a');

    if ($handle !== false) {
        // LOCK_EX because several PHPUnit processes hit this server at once.
        flock($handle, LOCK_EX);
        fwrite($handle, json_encode(['path' => $path, 'body' => $body])."\n");
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

header('Content-Type: application/json');

if (str_ends_with($path, '/runs')) {
    echo json_encode(['run' => ['seqNum' => 4242]]);

    return;
}

if (str_contains($path, 'results:report')) {
    /** @var array{results?: list<mixed>} $decoded */
    $decoded = json_decode($body, true) ?: [];

    echo json_encode(['status' => true, 'accepted' => (string) count($decoded['results'] ?? []), 'duplicates' => '0']);

    return;
}

echo json_encode(['run' => ['seqNum' => 4242]]);
