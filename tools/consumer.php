<?php
$runtime = new Kumwe\Engine\Runtime();
$tuple = $runtime->capabilities();
$lock = json_decode(file_get_contents(__DIR__ . '/../resources/engine-lock.json'), true, 512, JSON_THROW_ON_ERROR);
if ($tuple['embedded_engine_commit'] !== $lock['commit'] || $tuple['embedded_source_sha256'] !== $lock['archive_sha256']
    || !extension_loaded('kumwe_engine') || !(new ReflectionClass($runtime))->isInternal()) {
    throw new RuntimeException('Candidate source/module handshake does not match.');
}
require __DIR__ . '/../tests/common.inc';
$plan = literal_plan($runtime);
$result = $runtime->execute(['plan_id' => $plan['plan_id'], 'batch' => batch_envelope([document()])]);
if ($result['results'][0]['result']['value'] !== '123.4500') { throw new RuntimeException('Installed runtime failed.'); }
echo json_encode(['php' => PHP_VERSION, 'zts' => PHP_ZTS, 'debug' => PHP_DEBUG, 'os' => PHP_OS_FAMILY,
    'architecture' => php_uname('m'), 'tuple' => $tuple], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
