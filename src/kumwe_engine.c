#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php.h"
#include "main/php_main.h"
#include "Zend/zend_exceptions.h"
#include "Zend/zend_smart_str.h"
#include "ext/json/php_json.h"
#include "ext/random/php_random_csprng.h"
#include "ext/spl/spl_exceptions.h"
#include "ext/standard/info.h"
#include "ext/standard/base64.h"
#include <inttypes.h>
#include <float.h>
#include "php_kumwe_engine.h"
#include "php_kumwe_engine_build.h"
#include "kumwe_engine_build_config.h"
#include <kumwe/engine/engine.h>
#include <stdint.h>
#include <string.h>

#if PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600
#error This extension supports PHP 8.5 only.
#endif
#if SIZEOF_ZEND_LONG != 8
#error This binding requires 64-bit PHP integers.
#endif
#if DBL_MANT_DIG != 53 || FLT_RADIX != 2
#error Canonical transport requires IEEE-754 binary64.
#endif

#define BINDING_MAX_BYTES ((size_t)16777216)
#define BINDING_MAX_NODES ((size_t)262144)
#define BINDING_MAX_DEPTH 64
#define BINDING_MAX_PLANS 64

typedef struct {
    kumwe_engine_v1_plan *handle;
    size_t source_bytes;
} binding_plan;

typedef struct {
    HashTable plans;
    size_t source_bytes;
    zend_object std;
} runtime_object;

static zend_class_entry *runtime_ce;
static zend_class_entry *failure_ce;
static zend_object_handlers runtime_handlers;

static runtime_object *runtime_from_object(zend_object *object)
{
    return (runtime_object *)((char *)object - XtOffsetOf(runtime_object, std));
}

static void binding_failure(kumwe_engine_v1_status status)
{
    zend_throw_exception(failure_ce, "The native binding operation was refused.", (zend_long)status);
}

static void release_plan(zval *value)
{
    binding_plan *plan = (binding_plan *)Z_PTR_P(value);
    kumwe_engine_v1_plan_release(&plan->handle);
    efree(plan);
}

static zend_object *runtime_create(zend_class_entry *entry)
{
    runtime_object *object = zend_object_alloc(sizeof(runtime_object), entry);
    zend_object_std_init(&object->std, entry);
    object_properties_init(&object->std, entry);
    zend_hash_init(&object->plans, 8, NULL, release_plan, 0);
    object->source_bytes = 0;
    object->std.handlers = &runtime_handlers;
    return &object->std;
}

static void runtime_free(zend_object *standard)
{
    runtime_object *object = runtime_from_object(standard);
    zend_hash_destroy(&object->plans);
    zend_object_std_dtor(standard);
}

/* Marshal only inert values directly into JSON. No copied zval tree or caller
 * callback is created. Conservative byte/node/depth admission precedes encoding. */
enum copy_context { COPY_VALUE, COPY_MAP, COPY_DOCUMENTS, COPY_DOCUMENT, COPY_LINES, COPY_LINE_ROWS };

static zend_result encode_checked(zval *input, smart_str *output, unsigned depth,
    size_t *nodes, size_t *bytes, enum copy_context context)
{
    if (depth > BINDING_MAX_DEPTH || ++*nodes > BINDING_MAX_NODES
        || *bytes > BINDING_MAX_BYTES - 32) {
        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return FAILURE;
    }
    *bytes += 32;
    switch (Z_TYPE_P(input)) {
        case IS_NULL: smart_str_appends(output, "null"); return SUCCESS;
        case IS_FALSE: smart_str_appends(output, "false"); return SUCCESS;
        case IS_TRUE: smart_str_appends(output, "true"); return SUCCESS;
        case IS_LONG: smart_str_append_long(output, Z_LVAL_P(input)); return SUCCESS;
        case IS_STRING:
            if (Z_STRLEN_P(input) > (BINDING_MAX_BYTES - *bytes) / 6) {
                binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return FAILURE;
            }
            *bytes += Z_STRLEN_P(input) * 6;
            if (php_json_encode_ex(output, input,
                    PHP_JSON_UNESCAPED_SLASHES | PHP_JSON_UNESCAPED_UNICODE, 2) == FAILURE) {
                binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); return FAILURE;
            }
            return SUCCESS;
        case IS_ARRAY: {
            HashTable *table = Z_ARRVAL_P(input);
            if (zend_hash_num_elements(table) > BINDING_MAX_NODES - *nodes) {
                binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return FAILURE;
            }
            const bool map = context == COPY_MAP || context == COPY_DOCUMENT || context == COPY_LINES
                || !zend_array_is_list(table);
            zval *value;
            zend_string *key;
            bool first = true;
            smart_str_appendc(output, map ? '{' : '[');
            ZEND_HASH_FOREACH_STR_KEY_VAL(table, key, value) {
                if (map && key == NULL) {
                    binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); return FAILURE;
                }
                if (!first) { smart_str_appendc(output, ','); }
                first = false;
                if (key != NULL) {
                    if (ZSTR_LEN(key) > (BINDING_MAX_BYTES - *bytes) / 6) {
                        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return FAILURE;
                    }
                    *bytes += ZSTR_LEN(key) * 6;
                    zend_string *encoded_key = php_json_encode_string(ZSTR_VAL(key), ZSTR_LEN(key),
                        PHP_JSON_UNESCAPED_SLASHES | PHP_JSON_UNESCAPED_UNICODE);
                    if (encoded_key == NULL) {
                        binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); return FAILURE;
                    }
                    smart_str_append(output, encoded_key);
                    zend_string_release(encoded_key);
                    smart_str_appendc(output, ':');
                }
                enum copy_context child = COPY_VALUE;
                if (context == COPY_DOCUMENTS) { child = COPY_DOCUMENT; }
                if (context == COPY_LINES) { child = COPY_LINE_ROWS; }
                if (context == COPY_LINE_ROWS) { child = COPY_MAP; }
                if (context == COPY_DOCUMENT && key != NULL) {
                    if (zend_string_equals_literal(key, "fields")) { child = COPY_MAP; }
                    if (zend_string_equals_literal(key, "lines")) { child = COPY_LINES; }
                }
                if (depth == 0 && key != NULL && zend_string_equals_literal(key, "documents")) {
                    child = COPY_DOCUMENTS;
                }
                if (encode_checked(value, output, depth + 1, nodes, bytes, child) == FAILURE) {
                    return FAILURE;
                }
            } ZEND_HASH_FOREACH_END();
            smart_str_appendc(output, map ? '}' : ']');
            return SUCCESS;
        }
        default:
            binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); return FAILURE;
    }
}

static zend_result encode_envelope(zval *input, smart_str *buffer)
{
    size_t nodes = 0, bytes = 0;
    if (encode_checked(input, buffer, 0, &nodes, &bytes, COPY_MAP) == FAILURE) {
        smart_str_free(buffer); return FAILURE;
    }
    if (buffer->s == NULL || ZSTR_LEN(buffer->s) > BINDING_MAX_BYTES) {
        smart_str_free(buffer); binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return FAILURE;
    }
    smart_str_0(buffer);
    return SUCCESS;
}

static zend_result decode_buffer(kumwe_engine_v1_buffer *buffer, zval *result, size_t maximum_bytes)
{
    kumwe_engine_v1_view view = {sizeof(kumwe_engine_v1_view), 1, NULL, 0};
    if (kumwe_engine_v1_buffer_view(buffer, &view) != KUMWE_ENGINE_V1_OK || view.data == NULL
        || view.size > maximum_bytes) {
        binding_failure(KUMWE_ENGINE_V1_INTERNAL_FAILURE);
        return FAILURE;
    }
    if (php_json_decode_ex(result, (const char *)view.data, (size_t)view.size,
            PHP_JSON_OBJECT_AS_ARRAY | PHP_JSON_BIGINT_AS_STRING, BINDING_MAX_DEPTH + 8) == FAILURE
            || Z_TYPE_P(result) != IS_ARRAY) {
        if (!Z_ISUNDEF_P(result)) { zval_ptr_dtor(result); ZVAL_UNDEF(result); }
        binding_failure(KUMWE_ENGINE_V1_INTERNAL_FAILURE);
        return FAILURE;
    }
    return SUCCESS;
}

PHP_METHOD(Kumwe_Engine_Runtime, capabilities)
{
    ZEND_PARSE_PARAMETERS_NONE();
    static const uint8_t handshake[12] = {'K','E','C','1',1,0,0,0,0,0,0,0};
    kumwe_engine_v1_view input = {sizeof(kumwe_engine_v1_view), 1, handshake, sizeof(handshake)};
    kumwe_engine_v1_buffer *buffer = NULL;
    const kumwe_engine_v1_status status = kumwe_engine_v1_capabilities(&input, &buffer);
    if (status != KUMWE_ENGINE_V1_OK) {
        kumwe_engine_v1_buffer_release(&buffer); binding_failure(status); RETURN_THROWS();
    }
    zend_try {
        if (decode_buffer(buffer, return_value, BINDING_MAX_BYTES) == SUCCESS) {
            zval build;
            ZVAL_UNDEF(&build);
            if (php_json_decode_ex(&build, KUMWE_BINDING_BUILD_JSON, sizeof(KUMWE_BINDING_BUILD_JSON) - 1,
                    PHP_JSON_OBJECT_AS_ARRAY, 32) == FAILURE || Z_TYPE(build) != IS_ARRAY) {
                if (!Z_ISUNDEF(build)) { zval_ptr_dtor(&build); }
                binding_failure(KUMWE_ENGINE_V1_INTERNAL_FAILURE);
            } else {
                add_assoc_zval(return_value, "binding_build", &build);
                add_assoc_string(return_value, "binding_build_digest", KUMWE_BINDING_BUILD_SHA256);
            }
            add_assoc_string(return_value, "extension_package", "kumwe/kumwe-engine");
            add_assoc_string(return_value, "extension_module", "kumwe_engine");
            add_assoc_string(return_value, "extension_version", PHP_KUMWE_ENGINE_VERSION);
            add_assoc_string(return_value, "embedded_engine_commit", KUMWE_EMBEDDED_ENGINE_COMMIT);
            add_assoc_string(return_value, "embedded_source_sha256", KUMWE_EMBEDDED_ENGINE_SHA256);
            zval features; array_init(&features);
            add_assoc_zval(return_value, "binding_features", &features);
            zval *owned_features = zend_hash_str_find(Z_ARRVAL_P(return_value), "binding_features", sizeof("binding_features") - 1);
            add_next_index_string(owned_features, "opaque-compiled-results/1");
            add_assoc_long(return_value, "binding_max_plans", BINDING_MAX_PLANS);
            add_assoc_long(return_value, "binding_max_bytes", (zend_long)BINDING_MAX_BYTES);
        }
    } zend_catch {
        kumwe_engine_v1_buffer_release(&buffer);
        zend_bailout();
    } zend_end_try();
    kumwe_engine_v1_buffer_release(&buffer);
    if (EG(exception)) { RETURN_THROWS(); }
}

PHP_METHOD(Kumwe_Engine_Runtime, compile)
{
    zval *envelope;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(envelope)
    ZEND_PARSE_PARAMETERS_END();
    runtime_object *object = runtime_from_object(Z_OBJ_P(ZEND_THIS));
    if (zend_hash_num_elements(&object->plans) >= BINDING_MAX_PLANS) {
        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); RETURN_THROWS();
    }
    smart_str encoded = {0};
    if (encode_envelope(envelope, &encoded) == FAILURE) { RETURN_THROWS(); }
    const size_t source_size = ZSTR_LEN(encoded.s);
    if (source_size > BINDING_MAX_BYTES - object->source_bytes) {
        smart_str_free(&encoded); binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); RETURN_THROWS();
    }
    unsigned char entropy[16];
    char identity[33];
    static const char hex[] = "0123456789abcdef";
    if (php_random_bytes_silent(entropy, sizeof(entropy)) == FAILURE) {
        smart_str_free(&encoded); binding_failure(KUMWE_ENGINE_V1_INTERNAL_FAILURE); RETURN_THROWS();
    }
    for (size_t i = 0; i < sizeof(entropy); ++i) {
        identity[i * 2] = hex[entropy[i] >> 4]; identity[i * 2 + 1] = hex[entropy[i] & 15];
    }
    identity[32] = '\0';
    if (zend_hash_str_exists(&object->plans, identity, 32)) {
        smart_str_free(&encoded); binding_failure(KUMWE_ENGINE_V1_INTERNAL_FAILURE); RETURN_THROWS();
    }
    kumwe_engine_v1_view input = {sizeof(kumwe_engine_v1_view), 1,
        (const uint8_t *)ZSTR_VAL(encoded.s), source_size};
    /* Allocate Zend bookkeeping before Engine ownership begins: a Zend OOM
     * bailout must not strand a native allocation outside the object store. */
    binding_plan *plan = emalloc(sizeof(binding_plan));
    plan->handle = NULL;
    plan->source_bytes = source_size;
    kumwe_engine_v1_buffer *descriptor = NULL;
    kumwe_engine_v1_status status = kumwe_engine_v1_compile(&input, &plan->handle);
    smart_str_free(&encoded);
    if (status == KUMWE_ENGINE_V1_OK) { status = kumwe_engine_v1_plan_describe(plan->handle, &descriptor); }
    if (status != KUMWE_ENGINE_V1_OK) {
        kumwe_engine_v1_plan_release(&plan->handle); efree(plan);
        kumwe_engine_v1_buffer_release(&descriptor);
        binding_failure(status); RETURN_THROWS();
    }
    bool stored = false;
    zend_try {
        zval decoded;
        ZVAL_UNDEF(&decoded);
        if (decode_buffer(descriptor, &decoded, BINDING_MAX_BYTES) == SUCCESS) {
            array_init(return_value);
            add_assoc_stringl(return_value, "plan_id", identity, 32);
            add_assoc_zval(return_value, "descriptor", &decoded);
            zend_hash_str_add_ptr(&object->plans, identity, 32, plan);
            stored = true;
        }
    } zend_catch {
        kumwe_engine_v1_plan_release(&plan->handle); efree(plan);
        kumwe_engine_v1_buffer_release(&descriptor);
        zend_bailout();
    } zend_end_try();
    kumwe_engine_v1_buffer_release(&descriptor);
    if (stored) { object->source_bytes += source_size; }
    else { kumwe_engine_v1_plan_release(&plan->handle); efree(plan); }
    if (EG(exception)) { RETURN_THROWS(); }
}

PHP_METHOD(Kumwe_Engine_Runtime, release)
{
    zend_string *identity;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(identity)
    ZEND_PARSE_PARAMETERS_END();
    runtime_object *object = runtime_from_object(Z_OBJ_P(ZEND_THIS));
    binding_plan *plan = ZSTR_LEN(identity) == 32
        ? zend_hash_find_ptr(&object->plans, identity) : NULL;
    if (plan == NULL) {
        binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); RETURN_THROWS();
    }
    /* Never dereference the entry after hash deletion invokes release_plan. */
    const size_t source_size = plan->source_bytes;
    zend_hash_del(&object->plans, identity);
    object->source_bytes -= source_size;
}

/* KEC1 frames preserve PHP key kind, raw bytes and IEEE-754 bits. Only Engine
 * performs semantic admission, sorting, escaping, numeric formatting and hashing. */
static void canonical_u32(char *target, uint32_t value)
{
    for (unsigned i = 0; i < 4; ++i) { target[i] = (char)(value >> (8U * i)); }
}
static void canonical_u64(smart_str *output, uint64_t value)
{
    char bytes[8];
    for (unsigned i = 0; i < 8; ++i) { bytes[i] = (char)(value >> (8U * i)); }
    smart_str_appendl(output, bytes, sizeof(bytes));
}
static size_t canonical_frame(smart_str *output, unsigned char tag)
{
    const size_t offset = output->s == NULL ? 0 : ZSTR_LEN(output->s);
    smart_str_appendc(output, (char)tag);
    smart_str_appendl(output, "\0\0\0\0", 4);
    return offset;
}
static void canonical_finish_frame(smart_str *output, size_t offset)
{
    canonical_u32(ZSTR_VAL(output->s) + offset + 1, (uint32_t)(ZSTR_LEN(output->s) - offset - 5));
}
static zend_result canonical_encode(zval *input, smart_str *output, unsigned depth, size_t *nodes, size_t *bytes)
{
    ZVAL_DEREF(input);
    if (++*nodes > BINDING_MAX_NODES) {
        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return FAILURE;
    }
    if (depth > BINDING_MAX_DEPTH) {
        (void)canonical_frame(output, 7);
        return SUCCESS; /* Native depth admission precedes unsupported-value admission. */
    }
    unsigned char tag;
    switch (Z_TYPE_P(input)) {
        case IS_NULL: tag = 0; break;
        case IS_FALSE: tag = 1; break;
        case IS_TRUE: tag = 2; break;
        case IS_LONG: tag = 3; break;
        case IS_DOUBLE: tag = 4; break;
        case IS_STRING: tag = 5; break;
        case IS_ARRAY: tag = 6; break;
        default: tag = 7; break;
    }
    const size_t offset = canonical_frame(output, tag);
    switch (Z_TYPE_P(input)) {
        case IS_LONG: canonical_u64(output, (uint64_t)Z_LVAL_P(input)); break;
        case IS_DOUBLE: {
            uint64_t bits;
            const double number = Z_DVAL_P(input);
            memcpy(&bits, &number, sizeof(bits));
            canonical_u64(output, bits); break;
        }
        case IS_STRING:
            if (Z_STRLEN_P(input) > (size_t)33554432 - *bytes) { goto exhausted; }
            *bytes += Z_STRLEN_P(input);
            smart_str_append(output, Z_STR_P(input)); break;
        case IS_ARRAY: {
            zval *value;
            zend_string *key;
            zend_ulong index;
            const uint32_t count = zend_hash_num_elements(Z_ARRVAL_P(input));
            if (count > BINDING_MAX_NODES - *nodes) { goto exhausted; }
            char encoded_count[4]; canonical_u32(encoded_count, count);
            smart_str_appendl(output, encoded_count, sizeof(encoded_count));
            ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(input), index, key, value) {
                const size_t key_offset = canonical_frame(output, key == NULL ? 3 : 5);
                if (key == NULL) { canonical_u64(output, (uint64_t)index); }
                else {
                    if (ZSTR_LEN(key) > (size_t)33554432 - *bytes) { goto exhausted; }
                    *bytes += ZSTR_LEN(key);
                    smart_str_append(output, key);
                }
                canonical_finish_frame(output, key_offset);
                if (canonical_encode(value, output, depth + 1, nodes, bytes) == FAILURE) { return FAILURE; }
            } ZEND_HASH_FOREACH_END();
            break;
        }
        default: break;
    }
    canonical_finish_frame(output, offset);
    return SUCCESS;
exhausted:
    binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return FAILURE;
}

static void execute_canonical(zval *envelope, zval *return_value)
{
    static const char *keys[] = {"wire_version", "profile", "corpus_digest", "operation", "input", "limits"};
    HashTable *arguments = Z_ARRVAL_P(envelope);
    zval *original = zend_hash_str_find(arguments, "input", sizeof("input") - 1);
    const bool has_limits = zend_hash_str_exists(arguments, "limits", sizeof("limits") - 1);
    if (original == NULL || zend_hash_num_elements(arguments) != (has_limits ? 6U : 5U)) {
        binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); return;
    }
    smart_str encoded = {0};
    size_t nodes = 0, bytes = 0, metadata_nodes = 0, metadata_bytes = 0;
    smart_str_appendl(&encoded, "KEC1\0\0\0\0", 8);
    smart_str_appendc(&encoded, '{');
    for (size_t i = 0; i < 6; ++i) {
        if (i == 4 || (i == 5 && !has_limits)) { continue; }
        zval *value = zend_hash_str_find(arguments, keys[i], strlen(keys[i]));
        if (value == NULL) {
            smart_str_free(&encoded); binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); return;
        }
        if (i != 0) { smart_str_appendc(&encoded, ','); }
        smart_str_appendc(&encoded, '"'); smart_str_appends(&encoded, keys[i]); smart_str_appends(&encoded, "\":");
        if (encode_checked(value, &encoded, 0, &metadata_nodes, &metadata_bytes, i == 5 ? COPY_MAP : COPY_VALUE) == FAILURE) {
            smart_str_free(&encoded); return;
        }
    }
    smart_str_appendc(&encoded, '}');
    canonical_u32(ZSTR_VAL(encoded.s) + 4, (uint32_t)(ZSTR_LEN(encoded.s) - 8));
    if (canonical_encode(original, &encoded, 0, &nodes, &bytes) == FAILURE) {
        smart_str_free(&encoded); return;
    }
    if (encoded.s == NULL || ZSTR_LEN(encoded.s) > (size_t)67108864) {
        smart_str_free(&encoded); binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return;
    }
    smart_str_0(&encoded);
    kumwe_engine_v1_view input = {sizeof(kumwe_engine_v1_view), 1,
        (const uint8_t *)ZSTR_VAL(encoded.s), ZSTR_LEN(encoded.s)};
    kumwe_engine_v1_buffer *buffer = NULL;
    const kumwe_engine_v1_status status = kumwe_engine_v1_canonical(&input, &buffer);
    smart_str_free(&encoded);
    if (status != KUMWE_ENGINE_V1_OK) {
        kumwe_engine_v1_buffer_release(&buffer); binding_failure(status); return;
    }
    zend_try {
        decode_buffer(buffer, return_value, (size_t)67108864);
    } zend_catch {
        kumwe_engine_v1_buffer_release(&buffer); zend_bailout();
    } zend_end_try();
    kumwe_engine_v1_buffer_release(&buffer);
}

static uint32_t decimal_u32(const uint8_t *bytes)
{
    return (uint32_t)bytes[0] | ((uint32_t)bytes[1] << 8)
        | ((uint32_t)bytes[2] << 16) | ((uint32_t)bytes[3] << 24);
}

/* KED1/KER1 bytes are an opaque, bounded Engine ABI transport. No decimal
 * interpretation, rounding, arithmetic or string normalization occurs here. */
static void execute_decimal(zval *envelope, zval *return_value)
{
    HashTable *arguments = Z_ARRVAL_P(envelope);
    zval *version = zend_hash_str_find(arguments, "wire_version", sizeof("wire_version") - 1);
    zval *corpus = zend_hash_str_find(arguments, "corpus_digest", sizeof("corpus_digest") - 1);
    zval *payload = zend_hash_str_find(arguments, "input", sizeof("input") - 1);
    if (zend_hash_num_elements(arguments) != 4 || version == NULL || Z_TYPE_P(version) != IS_LONG
        || corpus == NULL || Z_TYPE_P(corpus) != IS_STRING || payload == NULL || Z_TYPE_P(payload) != IS_STRING) {
        binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); return;
    }
    if (Z_LVAL_P(version) != 1) { binding_failure(KUMWE_ENGINE_V1_UNSUPPORTED_VERSION); return; }
    if (!zend_string_equals_literal(Z_STR_P(corpus), KUMWE_BINDING_DECIMAL_CORPUS)) {
        binding_failure(KUMWE_ENGINE_V1_INCOMPATIBLE_CORPUS); return;
    }
    if (Z_STRLEN_P(payload) > (size_t)1048576) {
        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return;
    }
    kumwe_engine_v1_view input = {sizeof(kumwe_engine_v1_view), 1,
        (const uint8_t *)Z_STRVAL_P(payload), Z_STRLEN_P(payload)};
    kumwe_engine_v1_buffer *buffer = NULL;
    const kumwe_engine_v1_status status = kumwe_engine_v1_decimal_batch(&input, &buffer);
    if (status != KUMWE_ENGINE_V1_OK) {
        kumwe_engine_v1_buffer_release(&buffer); binding_failure(status); return;
    }
    kumwe_engine_v1_view output = {sizeof(kumwe_engine_v1_view), 1, NULL, 0};
    bool valid = kumwe_engine_v1_buffer_view(buffer, &output) == KUMWE_ENGINE_V1_OK
        && input.size >= 20 && output.data != NULL && output.size >= 8 && output.size <= 1048576
        && memcmp(output.data, "KER1", 4) == 0;
    size_t offset = 8;
    if (valid) {
        const uint32_t count = decimal_u32(output.data + 4);
        valid = count > 0 && count <= 4096 && count == decimal_u32(input.data + 4);
        for (uint32_t row = 0; valid && row < count; ++row) {
            if (output.size - offset < 4) { valid = false; break; }
            const uint32_t length = decimal_u32(output.data + offset);
            offset += 4;
            if (length == 0 || length > 68 || length > output.size - offset) { valid = false; break; }
            offset += length;
        }
        valid = valid && offset == output.size;
    }
    if (!valid) {
        kumwe_engine_v1_buffer_release(&buffer); binding_failure(KUMWE_ENGINE_V1_INTERNAL_FAILURE); return;
    }
    zend_try {
        array_init(return_value);
        add_assoc_long(return_value, "wire_version", 1);
        add_assoc_string(return_value, "profile", "decimal-batch-draft/1");
        add_assoc_stringl(return_value, "result", (const char *)output.data, (size_t)output.size);
    } zend_catch {
        kumwe_engine_v1_buffer_release(&buffer); zend_bailout();
    } zend_end_try();
    kumwe_engine_v1_buffer_release(&buffer);
}

/* The framed path carries opaque portable documents without JSON escaping their
 * already encoded input. Direct value-tree documents retain the JSON path. */
static zend_result encode_batch(zval *batch, smart_str *encoded, bool *framed)
{
    HashTable *root = Z_ARRVAL_P(batch);
    zval *documents = zend_hash_str_find(root, "documents", sizeof("documents") - 1);
    zval *limits = zend_hash_str_find(root, "limits", sizeof("limits") - 1);
    zval *version = zend_hash_str_find(root, "wire_version", sizeof("wire_version") - 1);
    *framed = false;
    if (zend_hash_num_elements(root) != 3 || documents == NULL || limits == NULL || version == NULL
        || Z_TYPE_P(documents) != IS_ARRAY || !zend_array_is_list(Z_ARRVAL_P(documents))) {
        return encode_envelope(batch, encoded);
    }
    zval *document;
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(documents), document) {
        if (Z_TYPE_P(document) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(document)) != 2) {
            return encode_envelope(batch, encoded);
        }
        zval *correlation = zend_hash_str_find(Z_ARRVAL_P(document), "correlation", sizeof("correlation") - 1);
        zval *input = zend_hash_str_find(Z_ARRVAL_P(document), "input", sizeof("input") - 1);
        if (correlation == NULL || input == NULL || Z_TYPE_P(correlation) != IS_STRING || Z_TYPE_P(input) != IS_STRING) {
            return encode_envelope(batch, encoded);
        }
    } ZEND_HASH_FOREACH_END();
    /* Same conservative admission charges as encode_checked: root+documents,
     * all three root keys, each document's map/two strings/two keys, then data. */
    size_t nodes = 2, bytes = 64 + 6 * (12 + 9 + 6);
    smart_str_appendl(encoded, "KEB1\0\0\0\0", 8);
    smart_str_appends(encoded, "{\"wire_version\":");
    if (encode_checked(version, encoded, 1, &nodes, &bytes, COPY_VALUE) == FAILURE) { goto failure; }
    smart_str_appends(encoded, ",\"limits\":");
    if (encode_checked(limits, encoded, 1, &nodes, &bytes, COPY_VALUE) == FAILURE) { goto failure; }
    smart_str_appendc(encoded, '}');
    canonical_u32(ZSTR_VAL(encoded->s) + 4, (uint32_t)(ZSTR_LEN(encoded->s) - 8));
    const uint32_t count = zend_hash_num_elements(Z_ARRVAL_P(documents));
    if (count > (BINDING_MAX_NODES - nodes) / 3) { goto exhausted; }
    char length[4]; canonical_u32(length, count); smart_str_appendl(encoded, length, 4);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(documents), document) {
        zval *correlation = zend_hash_str_find(Z_ARRVAL_P(document), "correlation", sizeof("correlation") - 1);
        zval *input = zend_hash_str_find(Z_ARRVAL_P(document), "input", sizeof("input") - 1);
        if (bytes > BINDING_MAX_BYTES - 192) { goto exhausted; }
        bytes += 192;
        zval *strings[] = {correlation, input};
        for (unsigned i = 0; i < 2; ++i) {
            const size_t size = Z_STRLEN_P(strings[i]);
            if (size > (BINDING_MAX_BYTES - bytes) / 6) { goto exhausted; }
            bytes += size * 6;
            canonical_u32(length, (uint32_t)size); smart_str_appendl(encoded, length, 4);
            smart_str_append(encoded, Z_STR_P(strings[i]));
        }
    } ZEND_HASH_FOREACH_END();
    if (ZSTR_LEN(encoded->s) > BINDING_MAX_BYTES) { goto exhausted; }
    smart_str_0(encoded); *framed = true; return SUCCESS;
exhausted:
    binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT);
failure:
    smart_str_free(encoded); return FAILURE;
}

static zend_result response_frame(const uint8_t **cursor, size_t *remaining, const char **data, size_t *size)
{
    if (*remaining < 4) { return FAILURE; }
    *size = decimal_u32(*cursor); *cursor += 4; *remaining -= 4;
    if (*size > *remaining) { return FAILURE; }
    *data = (const char *)*cursor; *cursor += *size; *remaining -= *size;
    return SUCCESS;
}
/* PHP's JSON scanner requires a NUL-terminated buffer even when a byte count
 * is supplied. A framed slice ends at another binary frame, so copy it first. */
static zend_result decode_json_frame(zval *result, const char *bytes, size_t size)
{
    if (size == 2 && ((bytes[0] == '[' && bytes[1] == ']') || (bytes[0] == '{' && bytes[1] == '}'))) {
        array_init(result); return SUCCESS;
    }
    zend_string *terminated = zend_string_init(bytes, size, 0);
    zend_result status = FAILURE;
    zend_try {
        // Three parent containers existed around this value in the JSON envelope.
        status = php_json_decode_ex(result, ZSTR_VAL(terminated), ZSTR_LEN(terminated),
            PHP_JSON_OBJECT_AS_ARRAY | PHP_JSON_BIGINT_AS_STRING, BINDING_MAX_DEPTH + 5);
    } zend_catch {
        zend_string_release(terminated);
        if (!Z_ISUNDEF_P(result)) { zval_ptr_dtor(result); ZVAL_UNDEF(result); }
        zend_bailout();
    } zend_end_try();
    zend_string_release(terminated);
    return status;
}

static zend_result decode_batch_buffer(kumwe_engine_v1_buffer *buffer, zval *result, bool framed, bool opaque)
{
    if (!framed) {
        if (decode_buffer(buffer, result, BINDING_MAX_BYTES) == FAILURE) { return FAILURE; }
        if (opaque) {
            zval *rows = zend_hash_str_find(Z_ARRVAL_P(result), "results", sizeof("results") - 1);
            if (rows == NULL || Z_TYPE_P(rows) != IS_ARRAY) { goto malformed; }
            zval *row;
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(rows), row) {
                if (Z_TYPE_P(row) != IS_ARRAY) { goto malformed; }
                zend_hash_str_del(Z_ARRVAL_P(row), "result", sizeof("result") - 1);
            } ZEND_HASH_FOREACH_END();
        }
        return SUCCESS;
    }
    kumwe_engine_v1_view view = {sizeof(kumwe_engine_v1_view), 1, NULL, 0};
    if (kumwe_engine_v1_buffer_view(buffer, &view) != KUMWE_ENGINE_V1_OK || view.size < 8
        || view.data == NULL || view.size > BINDING_MAX_BYTES || memcmp(view.data, "KER2", 4) != 0) { goto malformed; }
    const uint32_t count = decimal_u32(view.data + 4);
    const uint8_t *cursor = view.data + 8;
    size_t remaining = (size_t)view.size - 8;
    if (count > 4096 || count > remaining / 12) { goto malformed; }
    array_init(result);
    zval rows; array_init_size(&rows, count); add_assoc_zval(result, "results", &rows);
    add_assoc_long(result, "wire_version", 1);
    zval *owned_rows = zend_hash_str_find(Z_ARRVAL_P(result), "results", sizeof("results") - 1);
    for (uint32_t i = 0; i < count; ++i) {
        const char *correlation, *findings, *payload;
        size_t correlation_size, findings_size, payload_size;
        if (response_frame(&cursor, &remaining, &correlation, &correlation_size) == FAILURE
            || response_frame(&cursor, &remaining, &findings, &findings_size) == FAILURE
            || response_frame(&cursor, &remaining, &payload, &payload_size) == FAILURE) { goto malformed; }
        zval row; array_init(&row); add_next_index_zval(owned_rows, &row);
        zval *owned_row = zend_hash_index_find(Z_ARRVAL_P(owned_rows), i);
        add_assoc_stringl(owned_row, "correlation", correlation, correlation_size);
        zval decoded; ZVAL_UNDEF(&decoded);
        if (decode_json_frame(&decoded, findings, findings_size) == FAILURE || Z_TYPE(decoded) != IS_ARRAY) { zval_ptr_dtor(&decoded); goto malformed; }
        add_assoc_zval(owned_row, "findings", &decoded);
        if (!opaque) {
            ZVAL_UNDEF(&decoded);
            if (decode_json_frame(&decoded, payload, payload_size) == FAILURE || Z_TYPE(decoded) != IS_ARRAY) { zval_ptr_dtor(&decoded); goto malformed; }
            add_assoc_zval(owned_row, "result", &decoded);
        }
        add_assoc_stringl(owned_row, "result_json", payload, payload_size);
    }
    if (remaining != 0) { goto malformed; }
    return SUCCESS;
malformed:
    if (!Z_ISUNDEF_P(result)) { zval_ptr_dtor(result); ZVAL_UNDEF(result); }
    binding_failure(KUMWE_ENGINE_V1_INTERNAL_FAILURE); return FAILURE;
}

PHP_METHOD(Kumwe_Engine_Runtime, execute)
{
    zval *envelope;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(envelope)
    ZEND_PARSE_PARAMETERS_END();
    HashTable *arguments = Z_ARRVAL_P(envelope);
    zval *profile = zend_hash_str_find(arguments, "profile", sizeof("profile") - 1);
    if (profile != NULL && Z_TYPE_P(profile) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(profile), "kumwe-canonical-json/generic-v1")) {
        execute_canonical(envelope, return_value);
        if (EG(exception)) { RETURN_THROWS(); }
        return;
    }
    if (profile != NULL && Z_TYPE_P(profile) == IS_STRING
        && zend_string_equals_literal(Z_STR_P(profile), "decimal-batch-draft/1")) {
        execute_decimal(envelope, return_value);
        if (EG(exception)) { RETURN_THROWS(); }
        return;
    }
    zval *identity = zend_hash_str_find(arguments, "plan_id", sizeof("plan_id") - 1);
    zval *batch = zend_hash_str_find(arguments, "batch", sizeof("batch") - 1);
    zval *cancelled = zend_hash_str_find(arguments, "cancelled", sizeof("cancelled") - 1);
    zval *format = zend_hash_str_find(arguments, "result_format", sizeof("result_format") - 1);
    if (identity == NULL || Z_TYPE_P(identity) != IS_STRING || Z_STRLEN_P(identity) != 32
        || batch == NULL || Z_TYPE_P(batch) != IS_ARRAY
        || (cancelled != NULL && Z_TYPE_P(cancelled) != IS_TRUE && Z_TYPE_P(cancelled) != IS_FALSE)
        || (format != NULL && (Z_TYPE_P(format) != IS_STRING
            || (!zend_string_equals_literal(Z_STR_P(format), "both") && !zend_string_equals_literal(Z_STR_P(format), "opaque"))))
        || zend_hash_num_elements(arguments) != 2U + (cancelled != NULL ? 1U : 0U) + (format != NULL ? 1U : 0U)) {
        binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); RETURN_THROWS();
    }
    const bool opaque = format != NULL && zend_string_equals_literal(Z_STR_P(format), "opaque");
    runtime_object *object = runtime_from_object(Z_OBJ_P(ZEND_THIS));
    binding_plan *plan = zend_hash_find_ptr(&object->plans, Z_STR_P(identity));
    if (plan == NULL) { binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); RETURN_THROWS(); }
    smart_str encoded = {0};
    bool framed = false;
    if (encode_batch(batch, &encoded, &framed) == FAILURE) { RETURN_THROWS(); }
    kumwe_engine_v1_view input = {sizeof(kumwe_engine_v1_view), 1,
        (const uint8_t *)ZSTR_VAL(encoded.s), ZSTR_LEN(encoded.s)};
    kumwe_engine_v1_buffer *buffer = NULL;
    kumwe_engine_v1_cancellation *cancellation = NULL;
    kumwe_engine_v1_status status = KUMWE_ENGINE_V1_OK;
    if (cancelled != NULL && Z_TYPE_P(cancelled) == IS_TRUE) {
        status = kumwe_engine_v1_cancellation_create(&cancellation);
        if (status == KUMWE_ENGINE_V1_OK) { kumwe_engine_v1_cancellation_request(cancellation); }
    }
    if (status == KUMWE_ENGINE_V1_OK) { status = kumwe_engine_v1_execute(plan->handle, &input, cancellation, &buffer); }
    kumwe_engine_v1_cancellation_release(&cancellation);
    smart_str_free(&encoded);
    if (status != KUMWE_ENGINE_V1_OK) {
        kumwe_engine_v1_buffer_release(&buffer); binding_failure(status); RETURN_THROWS();
    }
    zend_try {
        decode_batch_buffer(buffer, return_value, framed, opaque);
    } zend_catch {
        kumwe_engine_v1_buffer_release(&buffer); zend_bailout();
    } zend_end_try();
    kumwe_engine_v1_buffer_release(&buffer);
    if (EG(exception)) { RETURN_THROWS(); }
}

#include "kumwe_engine_arginfo.h"

PHP_MINIT_FUNCTION(kumwe_engine)
{
    if (strcmp(php_version(), KUMWE_BINDING_PHP_VERSION) != 0) {
        php_error_docref(NULL, E_CORE_WARNING, "The executing PHP patch differs from the verified binding build");
        return FAILURE;
    }
    zend_class_entry entry;
    INIT_NS_CLASS_ENTRY(entry, "Kumwe\\Engine\\Exception", "BindingFailure", NULL);
    failure_ce = zend_register_internal_class_ex(&entry, spl_ce_RuntimeException);
    failure_ce->ce_flags |= ZEND_ACC_FINAL;
    INIT_NS_CLASS_ENTRY(entry, "Kumwe\\Engine", "Runtime", runtime_methods);
    runtime_ce = zend_register_internal_class(&entry);
    runtime_ce->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE;
    runtime_ce->create_object = runtime_create;
    memcpy(&runtime_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
    runtime_handlers.offset = XtOffsetOf(runtime_object, std);
    runtime_handlers.free_obj = runtime_free;
    runtime_handlers.clone_obj = NULL;
    return SUCCESS;
}

PHP_MINFO_FUNCTION(kumwe_engine)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "kumwe_engine", PHP_KUMWE_ENGINE_VERSION);
    php_info_print_table_row(2, "embedded Engine version", KUMWE_EMBEDDED_ENGINE_VERSION);
    php_info_print_table_row(2, "embedded Engine release", KUMWE_EMBEDDED_ENGINE_RELEASE[0] != '\0' ? KUMWE_EMBEDDED_ENGINE_RELEASE : "unreleased source");
    php_info_print_table_row(2, "embedded Engine commit", KUMWE_EMBEDDED_ENGINE_COMMIT);
#ifdef ZTS
    php_info_print_table_row(2, "thread safety", "ZTS");
#else
    php_info_print_table_row(2, "thread safety", "NTS");
#endif
    php_info_print_table_end();
}

static const zend_module_dep module_dependencies[] = {
    ZEND_MOD_REQUIRED("json")
    ZEND_MOD_REQUIRED("random")
    ZEND_MOD_REQUIRED("spl")
    ZEND_MOD_END
};

zend_module_entry kumwe_engine_module_entry = {
    STANDARD_MODULE_HEADER_EX, NULL, module_dependencies,
    "kumwe_engine", NULL, PHP_MINIT(kumwe_engine), NULL, NULL, NULL,
    PHP_MINFO(kumwe_engine), PHP_KUMWE_ENGINE_VERSION, STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_KUMWE_ENGINE
ZEND_GET_MODULE(kumwe_engine)
#endif
