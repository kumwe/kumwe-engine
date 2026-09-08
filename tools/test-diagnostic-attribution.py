#!/usr/bin/env python3
"""Package-origin regressions for captured distribution PHP and ELF dependencies."""
import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location(
    "diagnostic_runtime", Path(__file__).with_name("diagnostic-runtime.py"))
RUNTIME = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUNTIME)


class PackageAttributionTests(unittest.TestCase):
    def query(self, responses):
        """Simulate dpkg's ownership database, not filesystem-name inference."""
        def execute(argv, **kwargs):
            self.assertEqual(argv[:2], ["dpkg-query", "-S"])
            self.assertFalse(kwargs["check"])
            self.assertTrue(kwargs["text"])
            value = responses.get(argv[2])
            return subprocess.CompletedProcess(argv, 1 if value is None else 0,
                                               stdout="" if value is None else value)
        return patch.object(RUNTIME.subprocess, "run", side_effect=execute)

    def test_packaged_php_retains_actual_package_owner(self):
        with self.query({"/usr/bin/php8.5": "php8.5-cli: /usr/bin/php8.5\n"}):
            self.assertEqual(RUNTIME.package_for(Path("/usr/bin/php8.5")), "php8.5-cli")

    def test_multiarch_owner_is_not_split_at_its_architecture(self):
        path = "/usr/lib/x86_64-linux-gnu/libfixture.so.1"
        with self.query({path: "libfixture1:amd64: " + path + "\n"}):
            self.assertEqual(RUNTIME.package_for(Path(path)), "libfixture1:amd64")

    def test_merged_usr_dependency_retains_legacy_package_path(self):
        path = "/usr/lib/x86_64-linux-gnu/libfixture.so.1"
        legacy = "/lib/x86_64-linux-gnu/libfixture.so.1"
        with self.query({legacy: "libfixture1:amd64: " + legacy + "\n"}):
            self.assertEqual(RUNTIME.package_for(Path(path)), "libfixture1:amd64")

    def test_builder_php_without_a_package_is_refused(self):
        with self.query({}), self.assertRaisesRegex(RUNTIME.Invalid, "No installed package attribution"):
            RUNTIME.package_for(Path("/usr/bin/php8.5"))

    def test_package_with_different_owned_path_is_not_attribution(self):
        with self.query({"/usr/bin/php8.5": "php8.5-cli: /usr/bin/php8.4\n"}), \
                self.assertRaises(RUNTIME.Invalid):
            RUNTIME.package_for(Path("/usr/bin/php8.5"))

    def test_diversion_notice_cannot_supply_a_package_owner(self):
        with self.query({"/usr/bin/php8.5": "diversion by php8.5-cli: /usr/bin/php8.5\n"}), \
                self.assertRaises(RUNTIME.Invalid):
            RUNTIME.package_for(Path("/usr/bin/php8.5"))

    def test_malformed_package_owner_is_refused(self):
        with self.query({"/usr/bin/php8.5": "php8.5-cli;command: /usr/bin/php8.5\n"}), \
                self.assertRaises(RUNTIME.Invalid):
            RUNTIME.package_for(Path("/usr/bin/php8.5"))


if __name__ == "__main__":
    unittest.main()
