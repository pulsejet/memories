cimport h3lib
from .h3lib cimport bool, int64_t, H3int, H3ErrorCodes

from .util cimport (
    check_cell,
    check_index,
    check_distance,
)

from .error_system cimport (
    H3Error,
    check_for_error,
    check_for_error_msg,
)

from .memory cimport (
    H3MemoryManager,
    int_mv,
)

# todo: add notes about Cython exception handling

cpdef bool is_valid_index(H3int h):
    """Validates an H3 index (cell, vertex, or directed edge).

    Returns
    -------
    boolean
    """
    return h3lib.isValidIndex(h) == 1

# bool is a python type, so we don't need the except clause
cpdef bool is_valid_cell(H3int h):
    """Validates an H3 cell (hexagon or pentagon)

    Returns
    -------
    boolean
    """
    return h3lib.isValidCell(h) == 1


cpdef bool is_pentagon(H3int h):
    return h3lib.isPentagon(h) == 1


cpdef int get_base_cell_number(H3int h) except -1:
    check_cell(h)

    return h3lib.getBaseCellNumber(h)


cpdef int get_resolution(H3int h) except -1:
    """Returns the resolution of an H3 Index
    0--15
    """
    check_cell(h)

    return h3lib.getResolution(h)

cpdef int get_index_digit(H3int h, int res) except -1:
    cdef:
        int digit

    check_index(h)

    check_for_error(
        h3lib.getIndexDigit(h, res, &digit)
    )

    return digit

cpdef H3int construct_cell(int base_cell_number, const int[:] digits) except 0:
    cdef:
        H3int out
        int res = len(digits)
        H3Error err

    if res > 0:
        err = h3lib.constructCell(res, base_cell_number, &digits[0], &out)
    else:
        err = h3lib.constructCell(res, base_cell_number, NULL, &out)

    check_for_error(err)

    return out


cpdef int grid_distance(H3int h1, H3int h2) except -1:
    """ Compute the grid distance between two cells
    """
    cdef:
        int64_t distance

    check_cell(h1)
    check_cell(h2)

    check_for_error(
        h3lib.gridDistance(h1, h2, &distance)
    )

    return distance

cpdef H3int[:] grid_disk(H3int h, int k):
    """ Return cells at grid distance `<= k` from `h`.
    """
    cdef:
        int64_t n

    check_cell(h)
    check_distance(k)

    check_for_error(
        h3lib.maxGridDiskSize(k, &n)
    )

    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.gridDisk(h, k, hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv


cpdef H3int[:] grid_ring(H3int h, int k):
    """ Return cells at grid distance `== k` from `h`.
    Collection is "hollow" for k >= 1.
    """
    check_cell(h)
    check_distance(k)

    n = 6*k if k > 0 else 1
    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.gridRing(h, k, hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv


cpdef H3int cell_to_parent(H3int h, res=None) except 0:
    cdef:
        H3int parent

    check_cell(h)
    if res is None:
        res = get_resolution(h) - 1

    err = h3lib.cellToParent(h, res, &parent)
    if err:
        msg = 'Invalid parent resolution {} for cell {}.'
        msg = msg.format(res, hex(h))
        check_for_error_msg(err, msg)

    return parent


cpdef int64_t cell_to_children_size(H3int h, res=None) except -1:
    cdef:
        int64_t n

    check_cell(h)
    if res is None:
        res = get_resolution(h) + 1

    err = h3lib.cellToChildrenSize(h, res, &n)
    if err:
        msg = 'Invalid child resolution {} for cell {}.'
        msg = msg.format(res, hex(h))
        check_for_error_msg(err, msg)

    return n


cpdef H3int[:] cell_to_children(H3int h, res=None):
    check_cell(h)
    if res is None:
        res = get_resolution(h) + 1

    n = cell_to_children_size(h, res)

    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.cellToChildren(h, res, hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv



cpdef H3int cell_to_center_child(H3int h, res=None) except 0:
    cdef:
        H3int child

    check_cell(h)
    if res is None:
        res = get_resolution(h) + 1

    err = h3lib.cellToCenterChild(h, res, &child)
    if err:
        msg = 'Invalid child resolution {} for cell {}.'
        msg = msg.format(res, hex(h))
        check_for_error_msg(err, msg)

    return child


cpdef int64_t cell_to_child_pos(H3int child, int parent_res) except -1:
    cdef:
        int64_t child_pos

    check_cell(child)
    err = h3lib.cellToChildPos(child, parent_res, &child_pos)
    if err:
        msg = "Couldn't find child pos of cell {} at res {}."
        msg = msg.format(hex(child), parent_res)
        check_for_error_msg(err, msg)

    return child_pos


cpdef H3int child_pos_to_cell(H3int parent, int child_res, int64_t child_pos) except 0:
    cdef:
        H3int child

    check_cell(parent)
    err = h3lib.childPosToCell(child_pos, parent, child_res, &child)
    if err:
        msg = "Couldn't find child with pos {} at res {} from parent {}."
        msg = msg.format(child_pos, child_res, hex(parent))
        check_for_error_msg(err, msg)

    return child


cpdef H3int[:] compact_cells(const H3int[:] hu):
    # todo: fix this with my own Cython object "wrapper" class?
    #   everything has a .ptr interface?
    # todo: the Clib can handle 0-len arrays because it **avoids**
    # dereferencing the pointer, but Cython's syntax of
    # `&hu[0]` **requires** a dereference. For Cython, checking for array
    # length of zero and returning early seems like the easiest solution.
    # note: open to better ideas!

    if len(hu) == 0:
        return H3MemoryManager(0).to_mv()

    for h in hu: ## todo: should we have an array version? would that be faster?
        check_cell(h)

    cdef size_t n = len(hu)
    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.compactCells(&hu[0], hmm.ptr, n)
    )
    mv = hmm.to_mv()

    return mv


# todo: https://stackoverflow.com/questions/50684977/cython-exception-type-for-a-function-returning-a-typed-memoryview
# apparently, memoryviews are python objects, so we don't need to do the except clause
cpdef H3int[:] uncompact_cells(const H3int[:] hc, int res):
    # todo: the Clib can handle 0-len arrays because it **avoids**
    # dereferencing the pointer, but Cython's syntax of
    # `&hc[0]` **requires** a dereference. For Cython, checking for array
    # length of zero and returning early seems like the easiest solution.
    # note: open to better ideas!
    cdef:
        int64_t n


    if len(hc) == 0:
        return H3MemoryManager(0).to_mv()

    for h in hc:
        check_cell(h)

    check_for_error(
        h3lib.uncompactCellsSize(&hc[0], len(hc), res, &n)
    )

    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.uncompactCells(
            &hc[0], # todo: symmetry here with the wrapper object might be nice. hc.ptr / hc.n
            len(hc),
            hmm.ptr,
            hmm.n,
            res
        )
    )

    mv = hmm.to_mv()

    return mv


cpdef int64_t get_num_cells(int resolution) except -1:
    cdef:
        int64_t num_cells

    check_for_error(
        h3lib.getNumCells(resolution, &num_cells)
    )

    return num_cells


cpdef double average_hexagon_area(int resolution, unit='km^2') except -1:
    cdef:
        double area

    check_for_error(
        h3lib.getHexagonAreaAvgKm2(resolution, &area)
    )

    # todo: multiple units
    convert = {
        'km^2': 1.0,
        'm^2': 1000*1000.0
    }

    try:
        area *= convert[unit]
    except:
        raise ValueError('Unknown unit: {}'.format(unit))

    return area


cpdef double cell_area(H3int h, unit='km^2') except -1:
    cdef:
        double area

    if unit == 'rads^2':
        err = h3lib.cellAreaRads2(h, &area)
    elif unit == 'km^2':
        err = h3lib.cellAreaKm2(h, &area)
    elif unit == 'm^2':
        err = h3lib.cellAreaM2(h, &area)
    else:
        raise ValueError('Unknown unit: {}'.format(unit))

    check_for_error(err)

    return area


cdef _could_not_find_line(err, start, end):
    msg = "Couldn't find line between cells {} and {}"
    msg = msg.format(hex(start), hex(end))

    check_for_error_msg(err, msg)

cpdef H3int[:] grid_path_cells(H3int start, H3int end):
    cdef:
        int64_t n

    # todo: can we segfault here with invalid inputs?
    # Can we trust the c library to validate the start/end cells?
    # probably applies to all size/work pairs of functions...
    err = h3lib.gridPathCellsSize(start, end, &n)

    _could_not_find_line(err, start, end)

    hmm = H3MemoryManager(n)
    err = h3lib.gridPathCells(start, end, hmm.ptr)

    _could_not_find_line(err, start, end)

    # todo: probably here too?
    mv = hmm.to_mv()

    return mv

cpdef bool is_res_class_iii(H3int h):
    return h3lib.isResClassIII(h) == 1


cpdef H3int[:] get_pentagons(int res):
    n = h3lib.pentagonCount()

    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.getPentagons(res, hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv


cpdef H3int[:] get_res0_cells():
    n = h3lib.res0CellCount()

    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.getRes0Cells(hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv

# oh, this is returning a set??
# todo: convert to int[:]?
cpdef get_icosahedron_faces(H3int h):
    cdef:
        int n
        int[:] faces  ## todo: weird, this needs to be specified to avoid errors. cython bug?

    check_for_error(
        h3lib.maxFaceCount(h, &n)
    )

    faces = int_mv(n)
    check_for_error(
        h3lib.getIcosahedronFaces(h, &faces[0])
    )

    # todo: wait? do faces start from 0 or 1?
    # we could do this check/processing in the int_mv object
    out = [f for f in faces if f >= 0]

    return out


cpdef (int, int) cell_to_local_ij(H3int origin, H3int h) except *:
    cdef:
        h3lib.CoordIJ c

    err = h3lib.cellToLocalIj(origin, h, 0, &c)
    if err:
        msg = "Couldn't find local (i,j) between cells {} and {}."
        msg = msg.format(hex(origin), hex(h))
        check_for_error_msg(err, msg)

    return c.i, c.j

cpdef H3int local_ij_to_cell(H3int origin, int i, int j) except 0:
    cdef:
        h3lib.CoordIJ c
        H3int out

    c.i, c.j = i, j

    err = h3lib.localIjToCell(origin, &c, 0, &out)
    if err:
        msg = "Couldn't find cell at local ({},{}) from cell {}."
        msg = msg.format(i, j, hex(origin))
        check_for_error_msg(err, msg)

    return out
