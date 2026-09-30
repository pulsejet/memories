"""Shared model runtime: one inference semaphore across all backends."""

import asyncio

inference_sem = asyncio.Semaphore(1)
