"""The daemon bearer check rejects everything except the exact configured token."""

import unittest

from auth import bearer_matches

TOKEN = "0123456789abcdef"


class BearerMatchesTest(unittest.TestCase):
    def test_exact_bearer(self):
        self.assertTrue(bearer_matches(f"Bearer {TOKEN}", TOKEN))

    def test_rejects_near_misses(self):
        for header in (
            "",
            TOKEN,
            f"bearer {TOKEN}",
            f"Bearer {TOKEN} ",
            f"Bearer {TOKEN}x",
            "Bearer ",
            "Bearer short",
        ):
            self.assertFalse(bearer_matches(header, TOKEN), header)


if __name__ == "__main__":
    unittest.main()
