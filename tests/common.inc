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

// Fixture comparison ignores object member order while retaining scalar types and list order.
function same_fixture_value(mixed $actual, mixed $expected): bool
{
    if (get_debug_type($actual) !== get_debug_type($expected)) {
        return false;
    }
    if ($actual instanceof stdClass) {
        $actual = get_object_vars($actual);
        $expected = get_object_vars($expected);
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);
    }
    if (!is_array($actual)) {
        return $actual === $expected;
    }
    if (array_keys($actual) !== array_keys($expected)) {
        return false;
    }
    foreach ($actual as $key => $value) {
        if (!same_fixture_value($value, $expected[$key])) {
            return false;
        }
    }
    return true;
}

function document_fixture_program(stdClass $fixture): array
{
    $fields = [];
    foreach ($fixture->definition->fields as $field) {
        $projected = new stdClass();
        foreach (['handle', 'required', 'nullable', 'formula', 'validators', 'type', 'precision', 'scale',
            'length', 'normalizers'] as $key) {
            if (property_exists($field, $key)) { $projected->{$key} = $field->{$key}; }
        }
        $fields[] = $projected;
    }
    $invariants = [];
    foreach ($fixture->definition->record_invariants ?? [] as $rule) {
        $invariants[] = ['handle' => $rule->handle, 'condition' => $rule->condition];
    }
    return ['fields' => $fields, 'invariants' => $invariants];
}

function unicode_validator_plan(Kumwe\Engine\Runtime $runtime): array
{
    return $runtime->compile(['wire_version' => 1, 'profile' => 'normalized-document-draft/1',
        'corpus_digest' => hash_file('sha256', __DIR__ . '/../vendor/engine/corpus/document/document-profile-v1.json'),
        'program' => ['fields' => [['handle' => 'value',
            'validators' => [['rule' => 'pattern', 'value' => '^\p{L}+$']]]], 'invariants' => []]]);
}
