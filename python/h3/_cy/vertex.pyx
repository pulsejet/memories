cimport h3lib
from h3lib cimport bool, H3int

from .util cimport (
    check_cell,
    check_vertex,
    coord2deg
)

from .error_system cimport check_for_error

from .memory cimport H3MemoryManager


cpdef H3int cell_to_vertex(H3int h, int vertex_num) except 1:
    cdef:
        H3int out

    check_cell(h)

    check_for_error(
        h3lib.cellToVertex(h, vertex_num, &out)
    )

    return out

cpdef H3int[:] cell_to_vertexes(H3int h):
    cdef:
        H3int out
    
    check_cell(h)

    hmm = H3MemoryManager(6)
    check_for_error(
        h3lib.cellToVertexes(h, hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv

cpdef (double, double) vertex_to_latlng(H3int v) except *:
    cdef:
        h3lib.LatLng c

    check_vertex(v)

    check_for_error(
        h3lib.vertexToLatLng(v, &c)
    )

    return coord2deg(c)

cpdef bool is_valid_vertex(H3int v):
    return h3lib.isValidVertex(v) == 1
