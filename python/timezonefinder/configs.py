import os
from pathlib import Path
from typing import Any, Dict, List, Tuple, Union

import numpy as np

# SHORTCUT SETTINGS
# h3 library
SHORTCUT_H3_RES: int = 3

OCEAN_TIMEZONE_PREFIX = r"Etc/GMT"

# PATHS
PACKAGE_DIR = Path(__file__).parent
DEFAULT_DATA_DIR = PACKAGE_DIR / "data"


# i = signed 4byte integer
NR_BYTES_I = 4
# IMPORTANT: all values between -180 and 180 degree must fit into the domain of i4!
# is the same as testing if 360 fits into the domain of I4 (unsigned!)
MAX_ALLOWED_COORD_VAL = 2 ** (8 * NR_BYTES_I - 1)

# from math import floor,log10
# DECIMAL_PLACES_SHIFT = floor(log10(MAX_ALLOWED_COORD_VAL/180.0)) # == 7
DECIMAL_PLACES_SHIFT = 7
INT2COORD_FACTOR = 10 ** (-DECIMAL_PLACES_SHIFT)
COORD2INT_FACTOR = 10**DECIMAL_PLACES_SHIFT
MAX_LNG_VAL = 180.0
MAX_LAT_VAL = 90.0
MAX_LNG_VAL_INT = int(MAX_LNG_VAL * COORD2INT_FACTOR)
MAX_LAT_VAL_INT = int(MAX_LAT_VAL * COORD2INT_FACTOR)
MAX_INT_VAL = MAX_LNG_VAL_INT
assert MAX_INT_VAL < MAX_ALLOWED_COORD_VAL

# TYPES
# used in Numba JIT compiled function signatures in utils_numba.py
# NOTE: Changes in the global settings might not immediately affect
#  the functions due to caching!

# Type alias for flexibility with integer types (pure int or numpy integer scalars)
IntegerLike = Union[int, np.integer]

# hexagon id to list of polygon ids
ShortcutMapping = Dict[int, np.ndarray]
CoordPairs = List[Tuple[float, float]]
CoordLists = List[List[float]]
IntLists = List[List[int]]


# zone id storage settings ---------------------------------------------------

_ZONE_ID_DTYPE_ALIASES: Dict[str, "np.dtype[Any]"] = {
    "uint8": np.dtype("<u1"),
    "uint16": np.dtype("<u2"),
}


def _normalise_zone_id_dtype_key(key: str) -> str:
    """Normalise user provided dtype keys to canonical form."""
    return key.lower().strip()


def get_zone_id_dtype(name: str) -> "np.dtype[Any]":
    """Return the configured numpy dtype for storing zone IDs."""

    try:
        return _ZONE_ID_DTYPE_ALIASES[_normalise_zone_id_dtype_key(name)]
    except KeyError as exc:  # pragma: no cover - defensive, validated on import
        valid = ", ".join(sorted(_ZONE_ID_DTYPE_ALIASES))
        raise ValueError(
            f"Unsupported zone id dtype '{name}'. Choose one of: {valid}"
        ) from exc


def zone_id_dtype_to_string(dtype: np.dtype) -> str:
    """Return the little-endian numpy dtype string for serialisation."""

    return dtype.newbyteorder("<").str


def available_zone_id_dtype_names() -> Tuple[str, ...]:
    """Return the supported zone id dtype names."""

    return tuple(sorted(_ZONE_ID_DTYPE_ALIASES))


DEFAULT_ZONE_ID_DTYPE_NAME = os.getenv("TIMEZONEFINDER_ZONE_ID_DTYPE", "uint8")
DEFAULT_ZONE_ID_DTYPE = get_zone_id_dtype(DEFAULT_ZONE_ID_DTYPE_NAME)
