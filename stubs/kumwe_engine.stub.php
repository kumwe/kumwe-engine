<?php

/** @generate-class-entries */
namespace Kumwe\Engine {
    /** Owns at most 64 live native plans until release or object destruction; not cloneable or serializable. */
    final class Runtime
    {
        public function capabilities(): array {}
        public function compile(array $envelope): array {}
        /** Compiled calls accept result_format "both" (default) or "opaque" (omit decoded result). */
        public function execute(array $envelope): array {}

        /** Release a plan owned by this Runtime and reclaim its capacity. */
        public function release(string $planId): void {}
    }
}
namespace Kumwe\Engine\Exception {
    final class BindingFailure extends \RuntimeException {}
}
