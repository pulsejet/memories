def _in_scalar(x):
    return x


_out_scalar = _in_scalar


def _in_collection(x):
    import numpy as np
    # array is copied only if dtype does not match
    # `list`s should work, but not `set`s of integers
    return np.asarray(x, dtype='uint64')


_out_collection = _in_collection
