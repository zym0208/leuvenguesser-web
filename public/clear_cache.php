<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$cacheDir = dirname(__DIR__) . '/var/cache';

if (!is_dir($cacheDir)) {
    echo "Cache directory does not exist\n";
    exit;
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($cacheDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $path) {
    if ($path->isDir()) {
        rmdir($path->getPathname());
    } else {
        unlink($path->getPathname());
    }
}

echo "Cache cleared\n";
