<?php

/** @generate-class-entries */
namespace Kumwe\Engine {
    /** Owns at most 64 native plans until object destruction; not cloneable or serializable. */
    final class Runtime
    {
        public function capabilities(): array {}
        public function compile(array $envelope): array {}
        public function execute(array $envelope): array {}
    }
}
namespace Kumwe\Engine\Exception {
    final class BindingFailure extends \RuntimeException {}
}
