<?php

// Read-only localization checks. Usage: php scripts/audit-localization.php
require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Arr;

$root = dirname(__DIR__);
$catalogs = [];
$problems = [];
foreach (['en', 'sw'] as $locale) {
    foreach (glob($root.'/resources/lang/'.$locale.'/*.php') as $path) {
        $group = basename($path, '.php');
        $trees[$locale][$group] = require $path;
        $catalogs[$locale] = array_merge($catalogs[$locale] ?? [], Arr::dot([$group => require $path]));
    }
    $json[$locale] = json_decode(file_get_contents($root.'/resources/lang/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
}
foreach (['en' => 'sw', 'sw' => 'en'] as $locale => $other) {
    foreach (array_diff_key($catalogs[$locale], $catalogs[$other]) as $key => $value) {
        $problems[] = ['kind' => 'missing_catalog_key', 'locale' => $other, 'key' => $key];
    }
}
foreach ($catalogs['en'] as $key => $value) {
    if (! is_string($value) || ! isset($catalogs['sw'][$key])) {
        continue;
    }
    preg_match_all('/(?<![\w:]):[a-zA-Z_]+|{{\s*[a-zA-Z_]+\s*}}/', $value, $en);
    preg_match_all('/(?<![\w:]):[a-zA-Z_]+|{{\s*[a-zA-Z_]+\s*}}/', $catalogs['sw'][$key], $sw);
    sort($en[0]);
    sort($sw[0]);
    if ($en[0] !== $sw[0]) {
        $problems[] = ['kind' => 'placeholder_mismatch', 'key' => $key];
    }
}
$files = 0;
foreach (['app', 'resources/views', 'routes'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $files++;
        $source = file_get_contents($file->getPathname());
        preg_match_all("/(?:__|trans|@lang)\\(\\s*'((?:\\\\.|[^'\\\\])*)'/", $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$literal, $offset]) {
            $key = str_replace(["\\'", '\\n'], ["'", "\n"], $literal);
            if (preg_match('/^\s*\./', substr($source, $offset + strlen($literal) + 1)) || $key === '' || str_ends_with($key, '.') || str_contains($key, '$')) {
                continue;
            }
            foreach (['en', 'sw'] as $locale) {
                if (! Arr::has($trees[$locale], $key) && ! array_key_exists($key, $json[$locale])) {
                    $problems[] = ['kind' => 'missing_literal_translation', 'locale' => $locale, 'key' => $key, 'file' => substr($file->getPathname(), strlen($root) + 1), 'line' => substr_count(substr($source, 0, $offset), "\n") + 1];
                }
            }
        }
    }
}
echo json_encode(['source_files_checked' => $files, 'catalog_keys_per_language' => array_map('count', $catalogs), 'problems' => $problems], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($problems === [] ? 0 : 1);
