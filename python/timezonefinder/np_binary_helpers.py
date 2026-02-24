"""
Utility functions for handling .npy numpy binary files related to timezone data.
"""

from pathlib import Path

import numpy as np


def get_zone_ids_path(path: Path) -> Path:
    """Return the path to the zone_ids.npy file in the given directory."""
    return path / "zone_ids.npy"


def get_zone_positions_path(path: Path) -> Path:
    """Return the path to the zone_positions.npy file in the given directory."""
    return path / "zone_positions.npy"


def get_xmax_path(path: Path) -> Path:
    """Return the path to the xmax.npy file in the given directory."""
    return path / "xmax.npy"


def get_xmin_path(path: Path) -> Path:
    """Return the path to the xmin.npy file in the given directory."""
    return path / "xmin.npy"


def get_ymax_path(path: Path) -> Path:
    """Return the path to the ymax.npy file in the given directory."""
    return path / "ymax.npy"


def get_ymin_path(path: Path) -> Path:
    """Return the path to the ymin.npy file in the given directory."""
    return path / "ymin.npy"


def store_per_polygon_vector(file_path: Path, vector: np.ndarray) -> None:
    """Store a vector as a .npy file in the specified file path."""
    print(f"Storing vector to {file_path}")
    np.save(file_path, vector)


def read_per_polygon_vector(file_path: Path) -> np.ndarray:
    """Read a vector from a .npy file in the specified file path."""
    vector = np.load(file_path)
    return vector
