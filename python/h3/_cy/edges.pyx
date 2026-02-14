cimport h3lib
from .h3lib cimport bool, H3int

from .error_system cimport check_for_error

from .memory cimport H3MemoryManager

# todo: make bint
cpdef bool are_neighbor_cells(H3int h1, H3int h2):
    cdef:
        int out

    err = h3lib.areNeighborCells(h1, h2, &out)

    # note: we are intentionally not raising an error here, and just
    # returning false.
    # todo: is this choice consistent across the Python and C libs?
    if err:
        return False

    return out == 1


cpdef H3int cells_to_directed_edge(H3int origin, H3int destination) except *:
    cdef:
        int neighbor_out
        H3int out

    check_for_error(
        h3lib.cellsToDirectedEdge(origin, destination, &out)
    )

    return out


cpdef bool is_valid_directed_edge(H3int e):
    return h3lib.isValidDirectedEdge(e) == 1

cpdef H3int get_directed_edge_origin(H3int e) except 1:
    cdef:
        H3int out

    check_for_error(
        h3lib.getDirectedEdgeOrigin(e, &out)
    )

    return out

cpdef H3int get_directed_edge_destination(H3int e) except 1:
    cdef:
        H3int out

    check_for_error(
        h3lib.getDirectedEdgeDestination(e, &out)
    )

    return out

cpdef (H3int, H3int) directed_edge_to_cells(H3int e) except *:
    # todo: use directed_edge_to_cells in h3lib
    return get_directed_edge_origin(e), get_directed_edge_destination(e)

cpdef H3int[:] origin_to_directed_edges(H3int origin):
    """ Returns the 6 (or 5 for pentagons) directed edges
    for the given origin cell
    """

    hmm = H3MemoryManager(6)
    check_for_error(
        h3lib.originToDirectedEdges(origin, hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv


cpdef double average_hexagon_edge_length(int resolution, unit='km') except -1:
    cdef:
        double length

    check_for_error(
        h3lib.getHexagonEdgeLengthAvgKm(resolution, &length)
    )

    # todo: multiple units
    convert = {
        'km': 1.0,
        'm': 1000.0
    }

    try:
        length *= convert[unit]
    except:
        raise ValueError('Unknown unit: {}'.format(unit))

    return length


cpdef double edge_length(H3int e, unit='km') except -1:
    cdef:
        double length

    if unit == 'rads':
        err = h3lib.edgeLengthRads(e, &length)
    elif unit == 'km':
        err = h3lib.edgeLengthKm(e, &length)
    elif unit == 'm':
        err = h3lib.edgeLengthM(e, &length)
    else:
        raise ValueError('Unknown unit: {}'.format(unit))

    check_for_error(err)

    return length
