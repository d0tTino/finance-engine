<?php
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/composer/run-artisan.php <artisan-args>\n");
    exit(1);
}

$commandArgs = array_slice($argv, 1);

$ensureAppKey = static function (string $path): ?string {
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (false === $lines) {
        return null;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ('' === $trimmed || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_starts_with($trimmed, 'APP_KEY=')) {
            $value = substr($trimmed, strlen('APP_KEY='));
            if ('' !== $value) {
                return $value;
            }
        }
    }

    return null;
};

if ('' === (string) getenv('APP_KEY')) {
    $appKey = $ensureAppKey(__DIR__ . '/../../.env')
        ?? $ensureAppKey(__DIR__ . '/../../.env.testing')
        ?? 'base64:' . base64_encode(random_bytes(32));

    putenv('APP_KEY=' . $appKey);
    $_ENV['APP_KEY']    = $appKey;
    $_SERVER['APP_KEY'] = $appKey;
}

$binary  = escapeshellarg(PHP_BINARY);
$artisan = escapeshellarg(__DIR__ . '/../../artisan');
$args    = implode(' ', array_map('escapeshellarg', $commandArgs));

passthru(sprintf('%s %s %s', $binary, $artisan, $args), $exitCode);

exit((int) $exitCode);
