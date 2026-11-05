"""Shared-secret check for daemon requests. Not the Nextcloud app password."""

import hashlib
import hmac


def bearer_matches(header: str, token: str) -> bool:
    """True when header is exactly Bearer plus the configured token.

    Both sides are hashed first so a short or missing header does not leak the
    token length through compare_digest.
    """

    expected = hashlib.sha256(f"Bearer {token}".encode()).digest()
    actual = hashlib.sha256(header.encode()).digest()

    return hmac.compare_digest(expected, actual)
