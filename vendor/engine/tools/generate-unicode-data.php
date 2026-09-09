#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Generate only the pinned Unicode properties needed by native field normalization. */
function unicodeRequire(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function unicodeRead(string $path): string
{
    $content = file_get_contents($path);
    unicodeRequire($content !== false, 'Cannot read Unicode source: ' . $path);
    return $content;
}

/** @return Generator<int, list<string>> */
function unicodeRecords(string $source, string $version, string $name): Generator
{
    $handle = fopen($source . '/' . $version . '/' . $name, 'rb');
    unicodeRequire($handle !== false, 'Cannot open Unicode source: ' . $version . '/' . $name);
    try {
        while (($line = fgets($handle)) !== false) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line !== '') {
                yield array_map('trim', explode(';', $line));
            }
        }
        unicodeRequire(feof($handle), 'Cannot finish reading Unicode source: ' . $name);
    } finally {
        fclose($handle);
    }
}

/** @return list<int> */
function unicodePoints(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }
    return array_map(static fn(string $point): int => (int) hexdec($point), preg_split('/\s+/', $value));
}

/** @param array<int, list<int>> $decompositions
 *  @param list<int> $path
 *  @return list<int>
 */
function unicodeExpand(int $point, array $decompositions, array $path = []): array
{
    unicodeRequire(!in_array($point, $path, true) && count($path) < 32,
        'Recursive or excessively deep canonical decomposition.');
    if (!isset($decompositions[$point])) {
        return [$point];
    }
    $path[] = $point;
    $expanded = [];
    foreach ($decompositions[$point] as $item) {
        array_push($expanded, ...unicodeExpand($item, $decompositions, $path));
    }
    return $expanded;
}

try {
    $arguments = array_slice($argv, 1);
    unicodeRequire($arguments === [] || $arguments === ['--check'],
        'Usage: generate-unicode-data.php [--check]');
    unicodeRequire(PHP_INT_SIZE === 8, 'Unicode generation requires 64-bit PHP.');
    $root = dirname(__DIR__);
    $source = $root . '/third_party/unicode';
    $manifest = json_decode(unicodeRead($root . '/resources/unicode-source.json'), true, 512, JSON_THROW_ON_ERROR);
    unicodeRequire(is_array($manifest['files'] ?? null) && $manifest['files'] !== [],
        'Pinned Unicode source manifest has no files.');
    foreach ($manifest['files'] as $name => $expected) {
        unicodeRequire(is_string($expected) && hash_file('sha256', $source . '/' . $name) === $expected,
            'Pinned Unicode source changed: ' . $name);
    }

    $lower = [];
    $upper = [];
    foreach (unicodeRecords($source, '17.0.0', 'UnicodeData.txt') as $row) {
        $point = (int) hexdec($row[0]);
        if ($row[13] !== '') {
            $lower[$point] = [(int) hexdec($row[13])];
        }
        if ($row[12] !== '') {
            $upper[$point] = [(int) hexdec($row[12])];
        }
    }
    foreach (unicodeRecords($source, '17.0.0', 'SpecialCasing.txt') as $row) {
        if ($row[4] !== '') {
            // Default casing has exactly one language-independent context rule.
            $conditions = preg_split('/\s+/', $row[4]);
            if (array_intersect(['lt', 'tr', 'az'], $conditions) === []) {
                unicodeRequire(array_slice($row, 0, 5) === ['03A3', '03C2', '03A3', '03A3', 'Final_Sigma'],
                    'Unexpected language-independent casing context.');
            }
            continue;
        }
        $point = (int) hexdec($row[0]);
        $lower[$point] = unicodePoints($row[1]);
        $upper[$point] = unicodePoints($row[3]);
    }
    foreach ($lower as $point => $values) {
        if ($values === [$point]) {
            unset($lower[$point]);
        }
    }
    foreach ($upper as $point => $values) {
        if ($values === [$point]) {
            unset($upper[$point]);
        }
    }

    $properties = ['Cased' => [], 'Case_Ignorable' => []];
    foreach (unicodeRecords($source, '17.0.0', 'DerivedCoreProperties.txt') as $row) {
        if (array_key_exists($row[1], $properties)) {
            $limits = array_map(static fn(string $point): int => (int) hexdec($point), explode('..', $row[0]));
            $properties[$row[1]][] = [$limits[0], $limits[count($limits) - 1]];
        }
    }
    foreach ($properties as $name => $ranges) {
        usort($ranges, static fn(array $left, array $right): int => ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1]));
        $compact = [];
        foreach ($ranges as [$start, $end]) {
            $last = count($compact) - 1;
            if ($last >= 0 && $start <= $compact[$last][1] + 1) {
                $compact[$last][1] = max($end, $compact[$last][1]);
            } else {
                $compact[] = [$start, $end];
            }
        }
        $properties[$name] = $compact;
    }

    $classes = [];
    $decompositions = [];
    foreach (unicodeRecords($source, '15.1.0', 'UnicodeData.txt') as $row) {
        $point = (int) hexdec($row[0]);
        if ((int) $row[3] !== 0) {
            unicodeRequire(!str_ends_with($row[1], 'First>') && !str_ends_with($row[1], 'Last>'),
                'Combining class range requires explicit expansion.');
            $classes[$point] = (int) $row[3];
        }
        if ($row[5] !== '' && !str_starts_with($row[5], '<')) {
            $decompositions[$point] = unicodePoints($row[5]);
        }
    }
    $excluded = [];
    foreach (unicodeRecords($source, '15.1.0', 'CompositionExclusions.txt') as $row) {
        $excluded[(int) hexdec($row[0])] = true;
    }
    $compositions = [];
    foreach ($decompositions as $point => $decomposition) {
        // Full_Composition_Exclusion also includes singleton and non-starter decompositions.
        if (!isset($excluded[$point]) && count($decomposition) === 2 && ($classes[$decomposition[0]] ?? 0) === 0) {
            $key = ($decomposition[0] << 21) | $decomposition[1];
            unicodeRequire(!isset($compositions[$key]), 'Duplicate canonical composition pair.');
            $compositions[$key] = $point;
        }
    }

    $decomposed = [];
    foreach ($decompositions as $point => $_) {
        $decomposed[$point] = unicodeExpand($point, $decompositions);
        unicodeRequire(count($decomposed[$point]) <= 4, 'Canonical decomposition exceeds table capacity.');
    }
    foreach ([$lower, $upper] as $mapping) {
        foreach ($mapping as $values) {
            unicodeRequire(count($values) <= 3, 'Case mapping exceeds table capacity.');
        }
    }
    $lines = [
        '// Generated by tools/generate-unicode-data.php from pinned Unicode data. Do not edit.',
        '// Unicode License V3: third_party/unicode/LICENSE.',
        '#ifndef KUMWE_ENGINE_UNICODE_DATA_HPP', '#define KUMWE_ENGINE_UNICODE_DATA_HPP',
        '#include <cstdint>', '#include <cstddef>',
        'namespace kumwe::engine::document::unicode_data {',
        'struct mapping { std::uint32_t point; std::uint32_t values[4]; std::uint8_t size; };',
        'struct range { std::uint32_t first; std::uint32_t last; };',
        'struct combining { std::uint32_t point; std::uint8_t value; };',
        'struct composition { std::uint64_t pair; std::uint32_t point; };',
    ];
    foreach (['lowercase' => $lower, 'uppercase' => $upper, 'decomposition' => $decomposed] as $name => $data) {
        $lines[] = 'inline constexpr mapping ' . $name . '[] = {';
        ksort($data, SORT_NUMERIC);
        foreach ($data as $point => $values) {
            $padded = array_pad($values, 4, 0);
            $lines[] = '    {' . $point . ', {' . implode(', ', $padded) . '}, ' . count($values) . '},';
        }
        $lines[] = '};';
    }
    foreach ($properties as $name => $ranges) {
        $lines[] = 'inline constexpr range ' . strtolower($name) . '[] = {';
        foreach ($ranges as [$start, $end]) {
            $lines[] = '    {' . $start . ', ' . $end . '},';
        }
        $lines[] = '};';
    }
    $lines[] = 'inline constexpr combining classes[] = {';
    ksort($classes, SORT_NUMERIC);
    foreach ($classes as $point => $value) {
        $lines[] = '    {' . $point . ', ' . $value . '},';
    }
    array_push($lines, '};', 'inline constexpr composition compositions[] = {');
    ksort($compositions, SORT_NUMERIC);
    foreach ($compositions as $pair => $point) {
        $lines[] = '    {' . $pair . 'ULL, ' . $point . '},';
    }
    array_push($lines, '};', '}', '#endif', '');
    $generated = implode("\n", $lines);
    $target = $root . '/src/document/unicode_data.hpp';
    if ($arguments === ['--check']) {
        unicodeRequire(unicodeRead($target) === $generated,
            'Generated Unicode tables differ from pinned normative inputs.');
    } else {
        unicodeRequire(file_put_contents($target, $generated) === strlen($generated),
            'Cannot write generated Unicode tables.');
    }
    printf("Unicode 17 casing / 15.1 NFC tables verified: %d lower, %d upper, %d decompositions, %d compositions.\n",
        count($lower), count($upper), count($decomposed), count($compositions));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
