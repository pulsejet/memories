from cython.view cimport array
from .h3lib cimport H3int

"""
### Memory allocation options

We have a few options for the memory allocation functions.
There's a trade-off between using the Python allocators which let Python
track memory usage and offers some optimizations vs the system
allocators, which do not need to acquire the GIL.
"""

"""
System allocation functions. These do not acquire the GIL.
"""
from libc.stdlib cimport (
    # malloc as h3_malloc,  # not used
    calloc   as h3_calloc,
    realloc  as h3_realloc,
    free     as h3_free,
)


"""
PyMem_Raw* functions should just be wrappers around system allocators
also given in libc.stdlib. These functions do not acquire the GIL.

Note that these do not have a calloc function until py 3.5 and Cython 3.0,
so we would need to zero-out memory manually.

https://python.readthedocs.io/en/stable/c-api/memory.html#raw-memory-interface
"""
# from cpython.mem cimport (
#     PyMem_RawMalloc   as h3_malloc,
#     # PyMem_RawCalloc as h3_calloc,  # only in Python >=3.5 (and Cython >=3.0?)
#     PyMem_RawRealloc  as h3_realloc,
#     PyMem_RawFree     as h3_free,
# )


"""
These functions use the Python allocator (instead of the system allocator),
which offers some optimizations for Python, and allows Python to track
memory usage. However, these functions must acquire the GIL.

Note that these do not have a calloc function until py 3.5 and Cython 3.0,
so we would need to zero-out memory manually.

https://cython.readthedocs.io/en/stable/src/tutorial/memory_allocation.html
https://python.readthedocs.io/en/stable/c-api/memory.html#memory-interface
"""
# from cpython.mem cimport (
#     PyMem_Malloc   as h3_malloc,
#     # PyMem_Calloc as h3_calloc,  # only in Python >=3.5 (and Cython >=3.0?)
#     PyMem_Realloc  as h3_realloc,
#     PyMem_Free     as h3_free,
# )


cdef size_t move_nonzeros(H3int* a, size_t n):
    """ Move nonzero elements to front of array `a` of length `n`.
    Return the number of nonzero elements.

    Loop invariant: Everything *before* `i` or *after* `j` is "done".
    Move `i` and `j` inwards until they equal, and exit.
    You can move `i` forward until there's a zero in front of it.
    You can move `j` backward until there's a nonzero to the left of it.
    Anything to the right of `j` is "junk" that can be reallocated.

    | a | b | 0 | c | d | ... |
            ^           ^
            i           j


    | a | b | d | c | d | ... |
            ^       ^
            i       j
    """
    cdef:
        size_t i = 0
        size_t j = n

    while i < j:
        if a[j-1] == 0:
            j -= 1
            continue

        if a[i] != 0:
            i += 1
            continue

        # if we're here, we know:
        # a[i] == 0
        # a[j-1] != 0
        # i < j
        # so we can swap! (actually, move a[j-1] -> a[i])
        a[i] = a[j-1]
        j -= 1

    return i


cdef H3int[:] empty_memory_view():
    # todo: get rid of this?
    # there's gotta be a better way to do this...
    # create an empty cython.view.array?
    cdef:
        H3int a[1]

    return (<H3int[:]>a)[:0]


cdef _remove_zeros(H3MemoryManager x):
    x.n = move_nonzeros(x.ptr, x.n)

    if x.n == 0:
        h3_free(x.ptr)
        x.ptr = NULL
    else:
        x.ptr = <H3int*> h3_realloc(x.ptr, x.n*sizeof(H3int))
        if not x.ptr:
            raise MemoryError()


cdef H3int[:] _copy_to_mv(const H3int* ptr, size_t n):
    cdef:
        array arr

    arr = <H3int[:n]> ptr
    arr.callback_free_data = h3_free

    return arr


cdef H3int[:] _create_mv(H3MemoryManager x):
    if x.n == 0:
        h3_free(x.ptr)
        x.ptr = NULL
        mv = empty_memory_view()
    else:
        mv = _copy_to_mv(x.ptr, x.n)

        # responsibility for the memory moves from this object to the array/memoryview
        x.ptr = NULL
        x.n = 0

    return mv


"""
TODO: The not None declaration for the argument automatically rejects None values as input, which would otherwise be allowed. The reason why None is allowed by default is that it is conveniently used for return arguments:
      https://cython.readthedocs.io/en/latest/src/userguide/memoryviews.html#syntax

TODO: potential optimization: https://cython.readthedocs.io/en/latest/src/userguide/memoryviews.html#performance-disabling-initialization-checks

## future improvements:

- abolish any appearance of &thing[0]. (i.e., identical interfaces)
- can i make the interface for all these memory views identical?
"""

cdef class H3MemoryManager:
    """
    Cython object in charge of allocating and freeing memory for arrays
    of H3 indexes.

    Initially allocates memory and provides access through `self.ptr` and
    `self.n`.

    The `to_mv()` function removes responsibility for the allocated memory
    from this object to a memory view object. A memory view object automatically
    deallocates its memory during garbage collection.

    If the H3MemoryManager is garbage collected before running `to_mv()`,
    it will deallocate its memory itself.

    This pattern is useful for a few reasons:

    - provide convenient access to the raw memory pointer and length for passing
      to h3lib functions
    - remove zeroes from the array output (some h3lib functions may return
      results with zeros/H3NULL values)
    - cython and python array types have weird interfaces; memoryviews are
      much cleaner

    If we find a better way to do these then this class may no longer be
    necessary.

    TODO: consider a context manager pattern
    """
    def __cinit__(self, size_t n):
        self.n = n
        self.ptr = <H3int*> h3_calloc(self.n, sizeof(H3int))

        if not self.ptr:
            raise MemoryError()

    cdef H3int[:] to_mv_keep_zeros(self):
        # todo: this could be a private method
        return _create_mv(self)

    cdef H3int[:] to_mv(self):
        _remove_zeros(self)
        return _create_mv(self)

    def __dealloc__(self):
        # If the memory has been handed off to a memoryview, this pointer
        # should be NULL, and deallocing on NULL is fine.
        # If the pointer is *not* NULL, then this means the MemoryManager
        # has is still responsible for the memory (it hasn't given the memory away to another object).
        h3_free(self.ptr)


"""
todo: combine with the H3MemoryManager using fused types?
https://cython.readthedocs.io/en/stable/src/userguide/fusedtypes.html
"""
cdef int[:] int_mv(size_t n):
    cdef:
        array arr

    if n == 0:
        raise MemoryError()
    else:
        ptr = <int*> h3_calloc(n, sizeof(int))
        if ptr is NULL:
            raise MemoryError()

        arr = <int[:n]> ptr
        arr.callback_free_data = h3_free

        return arr


cpdef H3int[:] iter_to_mv(cells):
    """ cells needs to be an iterable that knows its size...
    or should we have it match the np.fromiter function, which infers if not available?
    """
    cdef:
        H3int[:] mv

    n = len(cells)
    mv = H3MemoryManager(n).to_mv_keep_zeros()

    for i,h in enumerate(cells):
        mv[i] = h

    return mv
