<?php
function formula_envelope(array $program): array
{
    return [
        'wire_version' => 1,
        'profile' => 'formula-draft/1',
        'corpus_digest' => hash_file('sha256', __DIR__ . '/../vendor/engine/corpus/definition/formula-v1.json'),
        'program' => $program,
    ];
}
function batch_envelope(array $documents): array
{
    return ['wire_version' => 1, 'documents' => $documents, 'limits' => [
        'max_input_bytes' => 1048576, 'max_output_bytes' => 1048576, 'max_documents' => 64,
        'max_findings' => 64, 'max_instructions' => 1000000, 'max_milliseconds' => 5000,
    ]];
}
function literal_plan(Kumwe\Engine\Runtime $runtime, mixed $value = '123.4500'): array
{
    return $runtime->compile(formula_envelope(['op' => 'literal', 'type' => 'string', 'value' => $value]));
}
function document(string $correlation = 'one', array $fields = [], array|null $lines = null): array
{
    return ['correlation' => $correlation, 'fields' => $fields, 'lines' => $lines];
}
