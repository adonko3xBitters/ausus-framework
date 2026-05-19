#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AUSUS starter — clean-room repo configurator.
 *
 * Fires from `post-root-package-install` during `composer create-project`,
 * BEFORE composer resolves the starter's cascading dependencies. Purpose:
 * keep `composer create-project ausus/starter` working in a true clean room
 * (no Packagist) by reading the operator's local-artifact-registry path from
 * the env var AUSUS_LOCAL_REGISTRY and writing it into the new project's
 * composer.json as a real `repositories` entry.
 *
 * Operator usage (clean-room / pre-Packagist):
 *   AUSUS_LOCAL_REGISTRY=/tmp/registry \
 *     composer create-project ausus/starter myapp \
 *       --repository='{"type":"artifact","url":"/tmp/registry"}'
 *
 * Operator usage (post-Packagist):
 *   composer create-project ausus/starter myapp
 *   # AUSUS_LOCAL_REGISTRY unset → script is a no-op → Packagist resolves deps.
 */

$registry = getenv('AUSUS_LOCAL_REGISTRY');
if ($registry === false || $registry === '') {
    // Packagist-publication mode. No-op.
    fwrite(STDOUT, "[ausus/starter] no AUSUS_LOCAL_REGISTRY set; using Packagist\n");
    exit(0);
}

$registry = realpath($registry) ?: $registry;
if (!is_dir($registry)) {
    fwrite(STDERR, "[ausus/starter] AUSUS_LOCAL_REGISTRY does not exist or is not a directory: $registry\n");
    exit(2);
}

$composerJsonPath = getcwd() . '/composer.json';
if (!is_file($composerJsonPath)) {
    fwrite(STDERR, "[ausus/starter] composer.json not found at $composerJsonPath\n");
    exit(3);
}

$raw = file_get_contents($composerJsonPath);
$obj = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

$obj['repositories'] = [
    'ausus-local'    => ['type' => 'artifact', 'url' => $registry],
    'packagist.org'  => false,
];

$encoded = json_encode(
    $obj,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
if ($encoded === false) {
    fwrite(STDERR, "[ausus/starter] could not re-encode composer.json\n");
    exit(4);
}

file_put_contents($composerJsonPath, $encoded . "\n");
fwrite(STDOUT, "[ausus/starter] configured clean-room artifact repo: $registry\n");
exit(0);
