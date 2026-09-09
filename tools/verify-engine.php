<?php

declare(strict_types=1);

require_once __DIR__ . '/engine-source.php';

$root = dirname(__DIR__);
$lock = \Kumwe\EngineSource\readLock($root);
\Kumwe\EngineSource\verifyBundle($root, $lock);
echo count($lock['files']) . ' embedded Engine source digests verified; Engine ' . ($lock['release'] ?? 'unreleased source')
    . ' (' . $lock['version'] . ' at ' . $lock['commit'] . ") and extension version " . $lock['version'] . " are hard-linked.\n";
