from .h3lib cimport H3int

cdef class H3MemoryManager:
    cdef:
        size_t n
        H3int* ptr

    cdef H3int[:] to_mv(self)
    cdef H3int[:] to_mv_keep_zeros(self)

cdef int[:] int_mv(size_t n)
cpdef H3int[:] iter_to_mv(cells)
