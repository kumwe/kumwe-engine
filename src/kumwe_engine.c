#ifdef HAVE_CONFIG_H
#include "config.h"
#endif
#include "php.h"
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
#include <kumwe/engine/engine.h>
#include <stdint.h>
#include <string.h>

#if PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600
#error This candidate supports PHP 8.5 only.
#endif
#if DBL_MANT_DIG != 53 || FLT_RADIX != 2
#error Canonical transport requires IEEE-754 binary64.
#endif
#ifdef ZTS
#error ZTS is not claimed by this candidate.
#endif

#define BINDING_MAX_BYTES ((size_t)16777216)
#define BINDING_MAX_NODES ((size_t)262144)
#define BINDING_MAX_DEPTH 64
#define BINDING_MAX_PLANS 64

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
    kumwe_engine_v1_plan *plan = (kumwe_engine_v1_plan *)Z_PTR_P(value);
    kumwe_engine_v1_plan_release(&plan);
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

/* Copy only inert values. No caller object, resource, reference, callback or float
 * can reach JSON. Budgets count conservative escaped bytes before encoding. */
enum copy_context { COPY_VALUE, COPY_MAP, COPY_DOCUMENTS, COPY_DOCUMENT, COPY_LINES, COPY_LINE_ROWS };

static zend_result checked_copy(zval *input, zval *output, unsigned depth,
    size_t *nodes, size_t *bytes, enum copy_context context)
{
    if (depth > BINDING_MAX_DEPTH || ++*nodes > BINDING_MAX_NODES) {
        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT);
        return FAILURE;
    }
    if (*bytes > BINDING_MAX_BYTES - 32) {
        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT);
        return FAILURE;
    }
    *bytes += 32;
    switch (Z_TYPE_P(input)) {
        case IS_NULL: ZVAL_NULL(output); return SUCCESS;
        case IS_FALSE: ZVAL_FALSE(output); return SUCCESS;
        case IS_TRUE: ZVAL_TRUE(output); return SUCCESS;
        case IS_LONG: ZVAL_LONG(output, Z_LVAL_P(input)); return SUCCESS;
        case IS_STRING:
            if (Z_STRLEN_P(input) > (BINDING_MAX_BYTES - *bytes) / 6) {
                binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT);
                return FAILURE;
            }
            *bytes += Z_STRLEN_P(input) * 6;
            ZVAL_STR_COPY(output, Z_STR_P(input));
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
            zend_ulong index;
            if (map) {
                object_init(output);
            } else {
                array_init_size(output, zend_hash_num_elements(table));
            }
            ZEND_HASH_FOREACH_KEY_VAL(table, index, key, value) {
                zval copied;
                ZVAL_UNDEF(&copied);
                if (map && key == NULL) {
                    binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT);
                    goto failed;
                }
                if (key != NULL) {
                    if (ZSTR_LEN(key) > (BINDING_MAX_BYTES - *bytes) / 6) {
                        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT);
                        goto failed;
                    }
                    *bytes += ZSTR_LEN(key) * 6;
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
                if (checked_copy(value, &copied, depth + 1, nodes, bytes, child) == FAILURE) {
                    goto failed;
                }
                if (map) {
                    zend_hash_update(Z_OBJPROP_P(output), key, &copied);
                } else {
                    zend_hash_index_update(Z_ARRVAL_P(output), index, &copied);
                }
            } ZEND_HASH_FOREACH_END();
            return SUCCESS;
failed:
            zval_ptr_dtor(output);
            ZVAL_UNDEF(output);
            return FAILURE;
        }
        default:
            binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT);
            return FAILURE;
    }
}

static zend_result encode_envelope(zval *input, smart_str *buffer)
{
    zval copied;
    size_t nodes = 0, bytes = 0;
    ZVAL_UNDEF(&copied);
    if (checked_copy(input, &copied, 0, &nodes, &bytes, COPY_MAP) == FAILURE) {
        return FAILURE;
    }
    const zend_result status = php_json_encode_ex(buffer, &copied,
        PHP_JSON_UNESCAPED_SLASHES | PHP_JSON_UNESCAPED_UNICODE, BINDING_MAX_DEPTH + 2);
    zval_ptr_dtor(&copied);
    if (status == FAILURE || buffer->s == NULL || ZSTR_LEN(buffer->s) > BINDING_MAX_BYTES) {
        smart_str_free(buffer);
        binding_failure(status == FAILURE ? KUMWE_ENGINE_V1_INVALID_INPUT : KUMWE_ENGINE_V1_EXHAUSTED_LIMIT);
        return FAILURE;
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
            add_assoc_string(return_value, "extension_version", PHP_KUMWE_ENGINE_VERSION);
            add_assoc_string(return_value, "embedded_engine_commit", KUMWE_EMBEDDED_ENGINE_COMMIT);
            add_assoc_string(return_value, "embedded_source_sha256", KUMWE_EMBEDDED_ENGINE_SHA256);
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
    kumwe_engine_v1_plan *plan = NULL;
    kumwe_engine_v1_buffer *descriptor = NULL;
    kumwe_engine_v1_status status = kumwe_engine_v1_compile(&input, &plan);
    smart_str_free(&encoded);
    if (status == KUMWE_ENGINE_V1_OK) { status = kumwe_engine_v1_plan_describe(plan, &descriptor); }
    if (status != KUMWE_ENGINE_V1_OK) {
        kumwe_engine_v1_plan_release(&plan); kumwe_engine_v1_buffer_release(&descriptor);
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
        kumwe_engine_v1_plan_release(&plan); kumwe_engine_v1_buffer_release(&descriptor);
        zend_bailout();
    } zend_end_try();
    kumwe_engine_v1_buffer_release(&descriptor);
    if (stored) { object->source_bytes += source_size; } else { kumwe_engine_v1_plan_release(&plan); }
    if (EG(exception)) { RETURN_THROWS(); }
}

/* Canonical tagged transport preserves PHP key kind, raw bytes and IEEE-754 bits.
 * Sorting, escaping, numeric formatting, semantic limits and SHA-256 stay in Engine. */
static zend_result canonical_tag(zval *input, zval *output, unsigned depth, size_t *nodes, size_t *bytes)
{
    ZVAL_DEREF(input);
    if (++*nodes > BINDING_MAX_NODES) {
        binding_failure(KUMWE_ENGINE_V1_EXHAUSTED_LIMIT); return FAILURE;
    }
    array_init(output);
    if (depth > BINDING_MAX_DEPTH) {
        add_assoc_string(output, "type", "unsupported");
        return SUCCESS; /* Native depth admission precedes unsupported-value admission. */
    }
    switch (Z_TYPE_P(input)) {
        case IS_NULL: add_assoc_string(output, "type", "null"); return SUCCESS;
        case IS_FALSE:
        case IS_TRUE:
            add_assoc_string(output, "type", "bool");
            add_assoc_bool(output, "value", Z_TYPE_P(input) == IS_TRUE); return SUCCESS;
        case IS_LONG:
            add_assoc_string(output, "type", "int");
            add_assoc_str(output, "decimal", zend_long_to_str(Z_LVAL_P(input))); return SUCCESS;
        case IS_DOUBLE: {
            uint64_t bits;
            char hex[17];
            const double number = Z_DVAL_P(input);
            memcpy(&bits, &number, sizeof(bits));
            snprintf(hex, sizeof(hex), "%016" PRIx64, bits);
            add_assoc_string(output, "type", "float");
            add_assoc_stringl(output, "hex", hex, 16); return SUCCESS;
        }
        case IS_STRING:
            if (Z_STRLEN_P(input) > (size_t)33554432 - *bytes) { goto exhausted; }
            *bytes += Z_STRLEN_P(input);
            add_assoc_string(output, "type", "string");
            add_assoc_str(output, "base64", php_base64_encode((const unsigned char *)Z_STRVAL_P(input), Z_STRLEN_P(input)));
            return SUCCESS;
        case IS_ARRAY: {
            zval entries, *value;
            zend_string *key;
            zend_ulong index;
            if (zend_hash_num_elements(Z_ARRVAL_P(input)) > BINDING_MAX_NODES - *nodes) { goto exhausted; }
            add_assoc_string(output, "type", "array");
            array_init(&entries);
            ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(input), index, key, value) {
                zval entry, tagged_key, tagged_value;
                ZVAL_UNDEF(&tagged_value);
                if (key != NULL) {
                    if (ZSTR_LEN(key) > (size_t)33554432 - *bytes) {
                        zval_ptr_dtor(&entries); goto exhausted;
                    }
                    *bytes += ZSTR_LEN(key);
                }
                if (canonical_tag(value, &tagged_value, depth + 1, nodes, bytes) == FAILURE) {
                    zval_ptr_dtor(&entries); zval_ptr_dtor(output); ZVAL_UNDEF(output); return FAILURE;
                }
                array_init(&entry);
                array_init(&tagged_key);
                if (key == NULL) {
                    add_assoc_string(&tagged_key, "type", "int");
                    add_assoc_str(&tagged_key, "decimal", zend_long_to_str((zend_long)index));
                } else {
                    add_assoc_string(&tagged_key, "type", "string");
                    add_assoc_str(&tagged_key, "base64", php_base64_encode((const unsigned char *)ZSTR_VAL(key), ZSTR_LEN(key)));
                }
                add_assoc_zval(&entry, "key", &tagged_key);
                add_assoc_zval(&entry, "value", &tagged_value);
                add_next_index_zval(&entries, &entry);
            } ZEND_HASH_FOREACH_END();
            add_assoc_zval(output, "entries", &entries);
            return SUCCESS;
        }
        default:
            add_assoc_string(output, "type", "unsupported"); return SUCCESS;
    }
exhausted:
    zval_ptr_dtor(output); ZVAL_UNDEF(output);
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
    zval request;
    size_t nodes = 0, bytes = 0, metadata_nodes = 0, metadata_bytes = 0;
    array_init(&request);
    for (size_t i = 0; i < 6; ++i) {
        if (i == 5 && !has_limits) { continue; }
        zval *value = zend_hash_str_find(arguments, keys[i], strlen(keys[i]));
        zval copied;
        ZVAL_UNDEF(&copied);
        if (value == NULL) {
            zval_ptr_dtor(&request); binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); return;
        }
        if ((i == 4 ? canonical_tag(value, &copied, 0, &nodes, &bytes)
                : checked_copy(value, &copied, 0, &metadata_nodes, &metadata_bytes, i == 5 ? COPY_MAP : COPY_VALUE)) == FAILURE) {
            zval_ptr_dtor(&request); return;
        }
        add_assoc_zval(&request, keys[i], &copied);
    }
    smart_str encoded = {0};
    const zend_result encoded_status = php_json_encode_ex(&encoded, &request,
        PHP_JSON_UNESCAPED_SLASHES | PHP_JSON_UNESCAPED_UNICODE, 512);
    zval_ptr_dtor(&request);
    if (encoded_status == FAILURE || encoded.s == NULL || ZSTR_LEN(encoded.s) > (size_t)67108864) {
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
    zval *identity = zend_hash_str_find(arguments, "plan_id", sizeof("plan_id") - 1);
    zval *batch = zend_hash_str_find(arguments, "batch", sizeof("batch") - 1);
    zval *cancelled = zend_hash_str_find(arguments, "cancelled", sizeof("cancelled") - 1);
    if (identity == NULL || Z_TYPE_P(identity) != IS_STRING || Z_STRLEN_P(identity) != 32
        || batch == NULL || Z_TYPE_P(batch) != IS_ARRAY
        || (cancelled != NULL && Z_TYPE_P(cancelled) != IS_TRUE && Z_TYPE_P(cancelled) != IS_FALSE)
        || zend_hash_num_elements(arguments) != (cancelled == NULL ? 2U : 3U)) {
        binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); RETURN_THROWS();
    }
    runtime_object *object = runtime_from_object(Z_OBJ_P(ZEND_THIS));
    kumwe_engine_v1_plan *plan = zend_hash_find_ptr(&object->plans, Z_STR_P(identity));
    if (plan == NULL) { binding_failure(KUMWE_ENGINE_V1_INVALID_INPUT); RETURN_THROWS(); }
    smart_str encoded = {0};
    if (encode_envelope(batch, &encoded) == FAILURE) { RETURN_THROWS(); }
    kumwe_engine_v1_view input = {sizeof(kumwe_engine_v1_view), 1,
        (const uint8_t *)ZSTR_VAL(encoded.s), ZSTR_LEN(encoded.s)};
    kumwe_engine_v1_buffer *buffer = NULL;
    kumwe_engine_v1_cancellation *cancellation = NULL;
    kumwe_engine_v1_status status = KUMWE_ENGINE_V1_OK;
    if (cancelled != NULL && Z_TYPE_P(cancelled) == IS_TRUE) {
        status = kumwe_engine_v1_cancellation_create(&cancellation);
        if (status == KUMWE_ENGINE_V1_OK) { kumwe_engine_v1_cancellation_request(cancellation); }
    }
    if (status == KUMWE_ENGINE_V1_OK) { status = kumwe_engine_v1_execute(plan, &input, cancellation, &buffer); }
    kumwe_engine_v1_cancellation_release(&cancellation);
    smart_str_free(&encoded);
    if (status != KUMWE_ENGINE_V1_OK) {
        kumwe_engine_v1_buffer_release(&buffer); binding_failure(status); RETURN_THROWS();
    }
    zend_try {
        decode_buffer(buffer, return_value, BINDING_MAX_BYTES);
    } zend_catch {
        kumwe_engine_v1_buffer_release(&buffer); zend_bailout();
    } zend_end_try();
    kumwe_engine_v1_buffer_release(&buffer);
    if (EG(exception)) { RETURN_THROWS(); }
}

#include "kumwe_engine_arginfo.h"

PHP_MINIT_FUNCTION(kumwe_engine)
{
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
    php_info_print_table_row(2, "embedded Engine commit", KUMWE_EMBEDDED_ENGINE_COMMIT);
    php_info_print_table_row(2, "release state", "candidate; not release-verified");
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
