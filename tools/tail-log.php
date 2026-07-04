<?php

declare(strict_types=1);

$path = $argv[1] ?? 'storage/logs/laravel.log';
$checkOnly = in_array('--check', $argv, true);
$path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

if (! is_file($path)) {
    $directory = dirname($path);

    if (! is_dir($directory)) {
        fwrite(STDERR, "Log directory does not exist: {$directory}" . PHP_EOL);
        exit(1);
    }

    if ($checkOnly) {
        echo "Log file is not present yet, but the directory exists: {$path}" . PHP_EOL;
        exit(0);
    }

    echo "Waiting for log file: {$path}" . PHP_EOL;

    while (! is_file($path)) {
        clearstatcache(true, $path);
        usleep(250000);
    }
}

if ($checkOnly) {
    echo "Log file is readable: {$path}" . PHP_EOL;
    exit(0);
}

$handle = fopen($path, 'rb');

if ($handle === false) {
    fwrite(STDERR, "Unable to open log file: {$path}" . PHP_EOL);
    exit(1);
}

fseek($handle, 0, SEEK_END);

while (true) {
    $line = fgets($handle);

    if ($line !== false) {
        echo $line;
        continue;
    }

    if (file_exists($path) && filesize($path) < ftell($handle)) {
        fclose($handle);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            fwrite(STDERR, "Unable to reopen log file: {$path}" . PHP_EOL);
            exit(1);
        }
    }

    clearstatcache(true, $path);
    usleep(250000);
}
