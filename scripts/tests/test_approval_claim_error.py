import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location("claim_error", pathlib.Path(__file__).parents[1] / "lib" / "approval_claim_error.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ClaimErrorTest(unittest.TestCase):
    def test_rate_limit_diagnostic(self):
        text = module.describe("503", {"code": "github_validation_unavailable", "stage": "pull_request", "upstream_status": 403, "retry_after": 120, "message": "private-token"})
        self.assertEqual(text, "Approval claim refused: HTTP 503; GitHub pull_request = 403; retry after 120 seconds")

    def test_arbitrary_upstream_content_is_never_rendered(self):
        text = module.describe("500", {"message": "private-token", "exception": "private-path"})
        self.assertEqual(text, "Approval claim refused: HTTP 500")
        self.assertEqual(module.describe("500", "private-token"), text)

    def test_malicious_typed_fields_are_discarded(self):
        text = module.describe("secret", {"code": "github_validation_unavailable", "stage": "private-token", "upstream_status": "private-token", "retry_after": True})
        self.assertEqual(text, "Approval claim refused: HTTP unknown; GitHub unknown = connection failure")


if __name__ == "__main__":
    unittest.main()
