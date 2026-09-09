<?php

declare(strict_types=1);

require_once __DIR__ . '/engine-source.php';

$root = dirname(__DIR__);
$lock = json_decode(\Kumwe\EngineSource\readFile($root . '/resources/engine-lock.json'), true, 512, JSON_THROW_ON_ERROR);
\Kumwe\EngineSource\verifyBundle($root, $lock);
echo count($lock['files']) . " embedded Engine source digests and native tooling policy verified; release verification is separate.\n";
