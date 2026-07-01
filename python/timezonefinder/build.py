"""optionally builds inside polygon algorithm C extension

Resources:
https://github.com/FirefoxMetzger/mini-extension
https://stackoverflow.com/questions/60073711/how-to-build-c-extensions-via-poetry
https://github.com/libmbd/libmbd/blob/master/build.py
"""

import pathlib
import re
from typing import Optional
import warnings

import cffi

EXTENSION_NAME = "inside_polygon_ext"
H_FILE_NAME = "inside_polygon_int.h"
C_FILE_NAME = "inside_polygon_int.c"
EXTENSION_PATH = pathlib.Path("timezonefinder") / "inside_poly_extension"
h_file_path = EXTENSION_PATH / H_FILE_NAME
c_file_path = EXTENSION_PATH / C_FILE_NAME

ffibuilder: Optional[cffi.FFI] = None
try:
    ffibuilder = cffi.FFI()
except Exception as exc:
    # Clang extension should be fully optional
    warnings.warn(
        f"C lang extension cannot be build, since cffi failed with this error: {exc}"
    )

if ffibuilder is not None:
    ffibuilder.set_source(
        "timezonefinder." + EXTENSION_NAME,
        source='#include "inside_polygon_int.h"',
        sources=[str(c_file_path)],
        include_dirs=[str(EXTENSION_PATH)],
    )
    with open(h_file_path) as h_file:
        # cffi does not like our preprocessor directives, so we remove them
        lns = h_file.read().splitlines()
        flt = filter(lambda ln: not re.match(r" *#", ln), lns)

    ffibuilder.cdef("\n".join(flt))


if __name__ == "__main__":
    if ffibuilder:
        ffibuilder.compile(verbose=True)
