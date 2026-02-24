"""'transparent' numba functionality replacements

njit decorator
data types

dtype_2int_tuple = typeof((1, 1))
@njit(b1(i4, i4, i4[:, :]), cache=True)
@njit(dtype_2int_tuple(f8, f8), cache=True)
"""


# decorator
def njit(*args, **kwargs):
    def wrapper(f):
        return f

    return wrapper


class SubscriptAndCallable:
    def __init__(self, *args, **kwargs):
        pass

    def __class_getitem__(cls, item):
        return None

    def __call__(self, arg):
        # for example int64(1) must work
        return arg


# DTYPES


class f8(SubscriptAndCallable):
    pass


class i8(SubscriptAndCallable):
    pass


class i4(SubscriptAndCallable):
    pass


class boolean(SubscriptAndCallable):
    pass


class Array(SubscriptAndCallable):
    pass
