from libc.stdint cimport uint64_t

cimport h3lib
from h3lib cimport bool, H3int

from .util cimport (
    check_cell,
    check_edge,
    check_res,
    deg2coord,
    coord2deg
)

from .error_system cimport check_for_error

from .memory cimport H3MemoryManager

# TODO: We might be OK with taking the GIL for the functions in this module
from libc.stdlib cimport (
    # malloc as h3_malloc,  # not used
    calloc   as h3_calloc,
    realloc  as h3_realloc,
    free     as h3_free,
)


cpdef H3int latlng_to_cell(double lat, double lng, int res) except 1:
    cdef:
        h3lib.LatLng c
        H3int out

    c = deg2coord(lat, lng)

    check_for_error(
        h3lib.latLngToCell(&c, res, &out)
    )

    return out


cpdef (double, double) cell_to_latlng(H3int h) except *:
    """Map an H3 cell into its centroid geo-coordinate (lat/lng)"""
    cdef:
        h3lib.LatLng c

    check_cell(h)
    # todo: think about: if you give this an invalid cell, should it still return a lat/lng?
    # idea: safe and unsafe APIs?

    check_for_error(
        h3lib.cellToLatLng(h, &c)
    )

    return coord2deg(c)


cdef h3lib.GeoLoop make_geoloop(latlngs) except *:
    """
    The returned `GeoLoop` must be freed with a call to `free_geoloop`.

    Parameters
    ----------
    latlngs : list or tuple
        GeoLoop: A sequence of >= 3 (lat, lng) pairs where the last
        element may or may not be same as the first (to form a closed loop).
        The order of the pairs may be either clockwise or counterclockwise.
    """
    cdef:
        h3lib.GeoLoop gl

    gl.numVerts = len(latlngs)

    # todo: need for memory management
    # can automatically free?
    gl.verts = <h3lib.LatLng*> h3_calloc(gl.numVerts, sizeof(h3lib.LatLng))

    for i, (lat, lng) in enumerate(latlngs):
        gl.verts[i] = deg2coord(lat, lng)

    return gl


cdef free_geoloop(h3lib.GeoLoop* gl):
    h3_free(gl.verts)
    gl.verts = NULL


cdef class GeoPolygon:
    cdef:
        h3lib.GeoPolygon gp

    def __cinit__(self, outer, holes=None):
        """

        Parameters
        ----------
        outer : list or tuple
            GeoLoop
            A GeoLoop is a sequence of >= 3 (lat, lng) pairs where the last
            element may or may not be same as the first (to form a closed loop).
            The order of the pairs may be either clockwise or counterclockwise.
        holes : list or tuple
            A sequence of GeoLoops
        """
        if holes is None:
            holes = []

        self.gp.geoloop = make_geoloop(outer)
        self.gp.numHoles = len(holes)
        self.gp.holes = NULL

        if len(holes) > 0:
            self.gp.holes =  <h3lib.GeoLoop*> h3_calloc(len(holes), sizeof(h3lib.GeoLoop))
            for i, hole in enumerate(holes):
                self.gp.holes[i] = make_geoloop(hole)


    def __dealloc__(self):
        free_geoloop(&self.gp.geoloop)

        for i in range(self.gp.numHoles):
            free_geoloop(&self.gp.holes[i])

        h3_free(self.gp.holes)


def polygon_to_cells(outer, int res, holes=None):
    """ Get the set of cells whose center is contained in a polygon.

    The polygon is defined similarity to the GeoJson standard, with an exterior
    `outer` ring of lat/lng points, and a list of `holes`, each of which are also
    rings of lat/lng points.

    Each ring may be in clockwise or counter-clockwise order
    (right-hand rule or not), and may or may not be a closed loop (where the last
    element is equal to the first).
    The GeoJSON spec requires the right-hand rule and a closed loop, but
    this function relaxes those constraints.

    Unlike the GeoJson standard, the elements of the lat/lng pairs of each
    ring are in lat/lng order, instead of lng/lat order.

    We'll handle translation to different formats in the Python code,
    rather than the Cython code.

    Parameters
    ----------
    outer : list or tuple
        A ring given by a sequence of lat/lng pairs.
    res : int
        The resolution of the output hexagons
    holes : list or tuple
        A collection of rings, each given by a sequence of lat/lng pairs.
        These describe any the "holes" in the polygon.
    """
    cdef:
        uint64_t n

    check_res(res)

    if not outer:
        return H3MemoryManager(0).to_mv()

    gp = GeoPolygon(outer, holes=holes)

    check_for_error(
        h3lib.maxPolygonToCellsSize(&gp.gp, res, 0, &n)
    )

    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.polygonToCells(&gp.gp, res, 0, hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv


def polygons_to_cells(polygons, int res):
    mvs = [
        polygon_to_cells(outer=poly.outer, res=res, holes=poly.holes)
        for poly in polygons
    ]

    n = sum(map(len, mvs))
    hmm = H3MemoryManager(n)

    # probably super inefficient, but it is working!
    # tood: move this to C
    k = 0
    for mv in mvs:
        for v in mv:
            hmm.ptr[k] = v
            k += 1

    return hmm.to_mv()


def polygon_to_cells_experimental(outer, int res, int flag, holes=None):
    """ Get the set of cells whose center is contained in a polygon.

    The polygon is defined similarity to the GeoJson standard, with an exterior
    `outer` ring of lat/lng points, and a list of `holes`, each of which are also
    rings of lat/lng points.

    Each ring may be in clockwise or counter-clockwise order
    (right-hand rule or not), and may or may not be a closed loop (where the last
    element is equal to the first).
    The GeoJSON spec requires the right-hand rule and a closed loop, but
    this function relaxes those constraints.

    Unlike the GeoJson standard, the elements of the lat/lng pairs of each
    ring are in lat/lng order, instead of lng/lat order.

    We'll handle translation to different formats in the Python code,
    rather than the Cython code.

    Parameters
    ----------
    outer : list or tuple
        A ring given by a sequence of lat/lng pairs.
    res : int
        The resolution of the output hexagons
    flag : int
        Polygon to cells flag, such as containment mode.
    holes : list or tuple
        A collection of rings, each given by a sequence of lat/lng pairs.
        These describe any the "holes" in the polygon.
    """
    cdef:
        uint64_t n

    check_res(res)

    if not outer:
        return H3MemoryManager(0).to_mv()

    gp = GeoPolygon(outer, holes=holes)

    check_for_error(
        h3lib.maxPolygonToCellsSizeExperimental(&gp.gp, res, flag, &n)
    )

    hmm = H3MemoryManager(n)
    check_for_error(
        h3lib.polygonToCellsExperimental(&gp.gp, res, flag, n, hmm.ptr)
    )
    mv = hmm.to_mv()

    return mv


def polygons_to_cells_experimental(polygons, int res, int flag):
    mvs = [
        polygon_to_cells_experimental(outer=poly.outer, res=res, holes=poly.holes, flag=flag)
        for poly in polygons
    ]

    n = sum(map(len, mvs))
    hmm = H3MemoryManager(n)

    # probably super inefficient, but it is working!
    # tood: move this to C
    k = 0
    for mv in mvs:
        for v in mv:
            hmm.ptr[k] = v
            k += 1

    return hmm.to_mv()


def cell_to_boundary(H3int h):
    """Compose an array of geo-coordinates that outlines a hexagonal cell"""
    cdef:
        h3lib.CellBoundary gb

    check_cell(h)

    h3lib.cellToBoundary(h, &gb)

    verts = tuple(
        coord2deg(gb.verts[i])
        for i in range(gb.num_verts)
    )

    return verts


def directed_edge_to_boundary(H3int edge):
    """ Returns the CellBoundary containing the coordinates of the edge
    """
    cdef:
        h3lib.CellBoundary gb

    check_edge(edge)

    h3lib.directedEdgeToBoundary(edge, &gb)

    # todo: move this verts transform into the CellBoundary object
    verts = tuple(
        coord2deg(gb.verts[i])
        for i in range(gb.num_verts)
    )

    return verts


cpdef double great_circle_distance(
    double lat1, double lng1,
    double lat2, double lng2, unit='km') except -1:

    a = deg2coord(lat1, lng1)
    b = deg2coord(lat2, lng2)

    if unit == 'rads':
        d = h3lib.greatCircleDistanceRads(&a, &b)
    elif unit == 'km':
        d = h3lib.greatCircleDistanceKm(&a, &b)
    elif unit == 'm':
        d = h3lib.greatCircleDistanceM(&a, &b)
    else:
        raise ValueError('Unknown unit: {}'.format(unit))

    return d
