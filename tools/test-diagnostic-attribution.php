#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Kumwe\Tools\DiagnosticAttribution;
require_once __DIR__ . '/diagnostic-runtime.php';

use Kumwe\Tools\DiagnosticRuntime\Invalid;
use function Kumwe\Tools\DiagnosticRuntime\packageFor;

/** Simulate package ownership records, never infer ownership from filenames. */
function query(array $responses): \Closure
{
    return static function (array $arguments) use ($responses): array {
        if (array_slice($arguments, 0, 2) !== ['dpkg-query', '-S'] || count($arguments) !== 3) {
            throw new \RuntimeException('Unexpected package attribution command.');
        }
        $response = $responses[$arguments[2]] ?? null;
        return [$response === null ? 1 : 0, $response ?? '', ''];
    };
}

function equals(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException('Unexpected attribution: ' . var_export($actual, true));
    }
}

function refused(array $responses, string $path): void
{
    try {
        packageFor($path, query($responses));
    } catch (Invalid $error) {
        if (!str_contains($error->getMessage(), 'No installed package attribution')) {
            throw $error;
        }
        return;
    }
    throw new \RuntimeException('Unattributed file was admitted.');
}

try {
    $php = '/usr/bin/php8.5';
    $library = '/usr/lib/x86_64-linux-gnu/libfixture.so.1';
    $legacy = '/lib/x86_64-linux-gnu/libfixture.so.1';
    equals('php8.5-cli', packageFor($php, query([$php => "php8.5-cli: $php\n"])));
    equals('libfixture1:amd64',
        packageFor($library, query([$library => "libfixture1:amd64: $library\n"])));
    equals('libfixture1:amd64',
        packageFor($library, query([$legacy => "libfixture1:amd64: $legacy\n"])));
    refused([], $php);
    refused([$php => "php8.5-cli: /usr/bin/php8.4\n"], $php);
    refused([$php => "diversion by php8.5-cli: $php\n"], $php);
    refused([$php => "php8.5-cli;command: $php\n"], $php);
    echo "7 diagnostic package-attribution checks passed.\n";
} catch (\Throwable $error) {
    fwrite(STDERR, 'Diagnostic attribution failed: ' . $error->getMessage() . "\n");
    exit(1);
}
