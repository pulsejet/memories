cimport h3lib
from h3lib cimport H3int
from .util cimport check_cell, coord2deg


# todo: it's driving me crazy that these three functions are all essentially the same linked list walker...
# grumble: no way to do iterators in with cdef functions!
cdef walk_polys(const h3lib.LinkedGeoPolygon* L):
    out = []
    while L:
        out += [walk_loops(L.data)]
        L = L.next

    return out


cdef walk_loops(const h3lib.LinkedGeoLoop* L):
    out = []
    while L:
        out += [walk_coords(L.data)]
        L = L.next

    return out


cdef walk_coords(const h3lib.LinkedLatLng* L):
    out = []
    while L:
        out += [coord2deg(L.data)]
        L = L.next

    return out

# todo: tuples instead of lists?
def _to_multi_polygon(const H3int[:] cells):
    cdef:
        h3lib.LinkedGeoPolygon polygon

    for h in cells:
        check_cell(h)

    h3lib.cellsToLinkedMultiPolygon(&cells[0], len(cells), &polygon)

    out = walk_polys(&polygon)

    # we're still responsible for cleaning up the passed in `polygon`,
    # but not a problem here, since it is stack allocated
    h3lib.destroyLinkedMultiPolygon(&polygon)

    return out


def cells_to_multi_polygon(const H3int[:] cells):
    # todo: gotta be a more elegant way to handle these...
    if len(cells) == 0:
        return []

    multipoly = _to_multi_polygon(cells)

    return multipoly
