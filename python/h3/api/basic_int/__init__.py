# This file is **symlinked** across the APIs to ensure they are exactly the same.
from typing import Literal
from array import array

from ... import _cy
from ..._h3shape import (
    H3Shape,
    LatLngPoly,
    LatLngMultiPoly,
    geo_to_h3shape,
    h3shape_to_geo,
)

from ._convert import (
    _in_scalar,
    _out_scalar,
    _in_collection,
    _out_collection,
)


def versions():
    """
    Version numbers for the Python (wrapper) and C (wrapped) libraries.

    Versions are output as strings of the form ``'X.Y.Z'``.
    C and Python should match on ``X`` (major) and ``Y`` (minor),
    but may differ on ``Z`` (patch).

    Returns
    -------
    dict like ``{'c': 'X.Y.Z', 'python': 'A.B.C'}``
    """
    from ..._version import __version__

    v = {
        'c': _cy.c_version(),
        'python': __version__,
    }

    return v


def str_to_int(h):
    """
    Converts a hexadecimal string to an H3 64-bit integer index.

    Parameters
    ----------
    h : str
        Hexadecimal string like ``'89754e64993ffff'``

    Returns
    -------
    int
        Unsigned 64-bit integer
    """
    return _cy.str_to_int(h)


def int_to_str(x):
    """
    Converts an H3 64-bit integer index to a hexadecimal string.

    Parameters
    ----------
    x : int
        Unsigned 64-bit integer

    Returns
    -------
    str
        Hexadecimal string like ``'89754e64993ffff'``
    """
    return _cy.int_to_str(x)


def get_num_cells(res):
    """
    Return the total number of *cells* (hexagons and pentagons)
    for the given resolution.

    Returns
    -------
    int
    """
    return _cy.get_num_cells(res)


def average_hexagon_area(res, unit='km^2'):
    """
    Return the average area of an H3 *hexagon*
    for the given resolution.

    This average *excludes* pentagons.

    Parameters
    ----------
    res : int
        H3 resolution
    unit: str
        Unit for area result (``'km^2'``, ``'m^2'``, or ``'rads^2'``)

    Returns
    -------
    float
    """
    return _cy.average_hexagon_area(res, unit)


def average_hexagon_edge_length(res, unit='km'):
    """
    Return the average *hexagon* edge length
    for the given resolution.

    This average *excludes* pentagons.

    Parameters
    ----------
    res : int
        H3 resolution
    unit: str
        Unit for length result (``'km'``, ``'m'``, or ``'rads'``)

    Returns
    -------
    float
    """
    return _cy.average_hexagon_edge_length(res, unit)


def is_valid_index(h):
    """Validates *any* H3 index (cell, vertex, or directed edge).

    Returns
    -------
    bool
    """
    try:
        h = _in_scalar(h)
        return _cy.is_valid_index(h)
    except (ValueError, TypeError):
        return False


def is_valid_cell(h):
    """
    Validates an H3 cell (hexagon or pentagon).

    Returns
    -------
    bool
    """
    try:
        h = _in_scalar(h)
        return _cy.is_valid_cell(h)
    except (ValueError, TypeError):
        return False


def is_valid_directed_edge(edge):
    """
    Validates an H3 unidirectional edge.

    Returns
    -------
    bool
    """
    try:
        e = _in_scalar(edge)
        return _cy.is_valid_directed_edge(e)
    except (ValueError, TypeError):
        return False


def latlng_to_cell(lat, lng, res):
    """
    Return the cell containing the (lat, lng) point
    for a given resolution.

    Returns
    -------
    H3Cell

    """
    return _out_scalar(_cy.latlng_to_cell(lat, lng, res))


def cell_to_latlng(h):
    """
    Return the center point of an H3 cell as a lat/lng pair.

    Parameters
    ----------
    h : H3Cell

    Returns
    -------
    lat : float
        Latitude
    lng : float
        Longitude
    """
    h = _in_scalar(h)
    return _cy.cell_to_latlng(h)


def get_resolution(h):
    """
    Return the resolution of an H3 cell.

    Parameters
    ----------
    h : H3Cell

    Returns
    -------
    int
    """
    # todo: could also work for edges
    h = _in_scalar(h)
    return _cy.get_resolution(h)


def cell_to_parent(h, res=None):
    """
    Get the parent of a cell.

    Parameters
    ----------
    h : H3Cell
    res : int or None, optional
        The resolution for the parent
        If ``None``, then ``res = resolution(h) - 1``

    Returns
    -------
    H3Cell
    """
    h = _in_scalar(h)
    p = _cy.cell_to_parent(h, res)
    p = _out_scalar(p)

    return p


def grid_distance(h1, h2):
    """
    Compute the grid distance between two cells.

    The grid distance is defined as the length of the shortest
    path between the cells in the graph formed by connecting
    adjacent cells.

    This function will raise an exception if the
    cells are too far apart to compute the distance.

    Parameters
    ----------
    h1 : H3Cell
    h2 : H3Cell

    Returns
    -------
    int
    """
    h1 = _in_scalar(h1)
    h2 = _in_scalar(h2)

    d = _cy.grid_distance(h1, h2)

    return d


def cell_to_boundary(h):
    """
    Return tuple of lat/lng pairs describing the cell boundary.

    Parameters
    ----------
    h : H3Cell

    Returns
    -------
    tuple of (lat, lng) tuples
    """
    h = _in_scalar(h)
    return _cy.cell_to_boundary(h)


def grid_disk(h, k=1):
    """
    Return unordered collection of cells with grid distance ``<= k`` from ``h``.
    That is, the "filled-in" disk.

    Parameters
    ----------
    h : H3Cell
    k : int
        Size of disk.

    Returns
    -------
    unordered collection of H3Cell

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """
    h = _in_scalar(h)
    mv = _cy.grid_disk(h, k)

    return _out_collection(mv)


def grid_ring(h, k=1):
    """
    Return unordered collection of cells with grid distance ``== k`` from ``h``.
    That is, the "hollow" ring.

    Parameters
    ----------
    h : H3Cell
    k : int
        Size of ring.

    Returns
    -------
    unordered collection of H3Cell

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """
    h = _in_scalar(h)
    mv = _cy.grid_ring(h, k)

    return _out_collection(mv)


def cell_to_children_size(h, res=None):
    """
    Number of children at resolution ``res`` of given cell.

    Parameters
    ----------
    h : H3Cell
    res : int or None, optional
        The resolution for the children.
        If ``None``, then ``res = resolution(h) + 1``

    Returns
    -------
    int
        Count of children
    """
    h = _in_scalar(h)
    return _cy.cell_to_children_size(h, res)


def cell_to_children(h, res=None):
    """
    Children of a cell as an unordered collection.

    Parameters
    ----------
    h : H3Cell
    res : int or None, optional
        The resolution for the children.
        If ``None``, then ``res = resolution(h) + 1``

    Returns
    -------
    unordered collection of H3Cell

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """
    h = _in_scalar(h)
    mv = _cy.cell_to_children(h, res)

    return _out_collection(mv)


def cell_to_child_pos(child, res_parent):
    """
    Child position index of given cell, with respect to its parent at ``res_parent``.

    The reverse operation can be done with ``child_pos_to_cell``.

    Parameters
    ----------
    child : H3Cell
    res_parent : int

    Returns
    -------
    int
        Integer index of the child with respect to parent cell.
    """
    child = _in_scalar(child)
    return _cy.cell_to_child_pos(child, res_parent)


def child_pos_to_cell(parent, res_child, child_pos):
    """
    Get child H3 cell from a parent cell, child resolution, and child position index.

    The reverse operation can be done with ``cell_to_child_pos``.

    Parameters
    ----------
    parent : H3Cell
    res_child : int
        Child cell resolution
    child_pos : int
        Integer position of child cell, releative to parent.


    Returns
    -------
    H3Cell
    """
    parent = _in_scalar(parent)
    child = _cy.child_pos_to_cell(parent, res_child, child_pos)
    child = _out_scalar(child)

    return child


# todo: nogil for expensive C operation?
def compact_cells(cells):
    """
    Compact a collection of H3 cells by combining
    smaller cells into larger cells, if all child cells
    are present. Input cells must all share the same resolution.

    Parameters
    ----------
    cells : iterable of H3 Cells

    Returns
    -------
    unordered collection of H3Cell

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """
    hu = _in_collection(cells)
    hc = _cy.compact_cells(hu)

    return _out_collection(hc)


def uncompact_cells(cells, res):
    """
    Reverse the ``compact_cells`` operation.

    Return a collection of H3 cells, all of resolution ``res``.

    Parameters
    ----------
    cells : iterable of H3Cell
    res : int
        Resolution of desired output cells.

    Returns
    -------
    unordered collection of H3Cell

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """
    # TODO: add test to make sure an error is returned when input contains cell
    # smaller than output res.

    hc = _in_collection(cells)
    hu = _cy.uncompact_cells(hc, res)

    return _out_collection(hu)


def polygon_to_cells(h3shape, res):
    """
    Alias for ``h3shape_to_cells``.
    """
    return h3shape_to_cells(h3shape, res)


def h3shape_to_cells(h3shape, res):
    """
    Return the collection of H3 cells at a given resolution whose center points
    are contained within an ``LatLngPoly`` or ``LatLngMultiPoly``.

    Parameters
    ----------
    h3shape : ``H3Shape``
    res : int
        Resolution of the output cells

    Returns
    -------
    list of H3Cell

    Examples
    --------

    >>> poly = LatLngPoly(
    ...     [(37.68, -122.54), (37.68, -122.34), (37.82, -122.34),
    ...      (37.82, -122.54)],
    ... )
    >>> h3.h3shape_to_cells(poly, 6)
    ['862830807ffffff',
     '862830827ffffff',
     '86283082fffffff',
     '862830877ffffff',
     '862830947ffffff',
     '862830957ffffff',
     '86283095fffffff']

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """

    # todo: not sure if i want this dispatch logic here. maybe in the objects?
    if isinstance(h3shape, LatLngPoly):
        poly = h3shape
        mv = _cy.polygon_to_cells(poly.outer, res=res, holes=poly.holes)
    elif isinstance(h3shape, LatLngMultiPoly):
        mpoly = h3shape
        mv = _cy.polygons_to_cells(mpoly.polys, res=res)
    elif isinstance(h3shape, H3Shape):
        raise ValueError('Unrecognized H3Shape: ' + str(h3shape))
    else:
        raise ValueError('Unrecognized type: ' + str(type(h3shape)))

    return _out_collection(mv)


def polygon_to_cells_experimental(
    h3shape: H3Shape,
    res: int,
    contain: Literal['center', 'full', 'overlap', 'bbox_overlap'] = 'center',
):
    """
    Alias for ``h3shape_to_cells_experimental``.
    """
    return h3shape_to_cells_experimental(h3shape, res, contain)


def h3shape_to_cells_experimental(
    h3shape: H3Shape,
    res: int,
    contain: Literal['center', 'full', 'overlap', 'bbox_overlap'] = 'center',
):
    """
    Experimental function similar to ``h3shape_to_cells``, but with support for
    multiple cell containment modes.

    Using ``contain='center'`` should give identical behavior as
    ``h3shape_to_cells``.

    Note that this function is **experimental** and has no API stability gaurantees
    across versions, so it may change in the future.


    Parameters
    ----------
    h3shape : ``H3Shape``
    res : int
        Resolution of the output cells
    contain : {'center', 'full', 'overlap', 'bbox_overlap'}, optional
        Specifies the containment condition.
            - 'center': Cell center is contained in shape
            - 'full': Cell is fully contained in shape
            - 'overlap': Cell is partially contained in shape
            - 'bbox_overlap': Cell bounding box is partially contained in shape

        Default is 'center'.

    Returns
    -------
    list of H3Cell

    Examples
    --------

    >>> poly = LatLngPoly(
    ...     [(37.68, -122.54), (37.68, -122.34), (37.82, -122.34),
    ...      (37.82, -122.54)],
    ... )
    >>> h3.h3shape_to_cells_experimental(poly, 6, 'center')
    ['862830807ffffff',
     '862830827ffffff',
     '86283082fffffff',
     '862830877ffffff',
     '862830947ffffff',
     '862830957ffffff',
     '86283095fffffff']

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """

    contain_modes = {
        'center': 0,
        'full': 1,
        'overlap': 2,
        'bbox_overlap': 3,
    }

    flag = contain_modes[contain]

    # todo: not sure if i want this dispatch logic here. maybe in the objects?
    if isinstance(h3shape, LatLngPoly):
        poly = h3shape
        mv = _cy.polygon_to_cells_experimental(
            poly.outer,
            res = res,
            holes = poly.holes,
            flag = flag,
        )
    elif isinstance(h3shape, LatLngMultiPoly):
        mpoly = h3shape
        mv = _cy.polygons_to_cells_experimental(mpoly.polys, res=res, flag=flag)
    elif isinstance(h3shape, H3Shape):
        raise ValueError('Unrecognized H3Shape: ' + str(h3shape))
    else:
        raise ValueError('Unrecognized type: ' + str(type(h3shape)))

    return _out_collection(mv)


def cells_to_h3shape(cells, *, tight=True):
    """
    Return an ``H3Shape`` describing the area covered by a collection of H3 cells.
    Will return ``LatLngPoly`` or ``LatLngMultiPoly``.

    Parameters
    ----------
    cells : iterable of H3 cells
    tight : bool
        If True, return ``LatLngPoly`` if possible.
        If False, always return ``LatLngMultiPoly``.

    Returns
    -------
    LatLngPoly | LatLngMultiPoly

    Examples
    --------

    >>> cells = ['8428309ffffffff', '842830dffffffff']
    >>> h3.cells_to_h3shape(cells, tight=True)
    <LatLngPoly: [10]>
    >>> h3.cells_to_h3shape(cells, tight=False)
    <LatLngMultiPoly: [10]>
    """
    cells = _in_collection(cells)
    mpoly = _cy.cells_to_multi_polygon(cells)

    polys = [LatLngPoly(*poly) for poly in mpoly]
    out = LatLngMultiPoly(*polys)

    if tight and len(out) == 1:
        out = out[0]

    return out


def geo_to_cells(geo, res):
    """Convert from ``__geo_interface__`` to cells.

    Parameters
    ----------
    geo : an object implementing ``__geo_interface__`` or a dictionary in that format.
        Both ``LatLngPoly`` and ``LatLngMultiPoly`` implement the interface.
    res : int
        Resolution of desired output cells.

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """
    h3shape = geo_to_h3shape(geo)
    return h3shape_to_cells(h3shape, res)


def cells_to_geo(cells, tight=True):
    """
    Convert from cells to a ``__geo_interface__`` dict.

    Parameters
    ----------
    cells : iterable of H3 Cells
    tight : bool
        When ``True``, returns an ``LatLngPoly`` when possible.
        When ``False``, always returns an ``LatLngMultiPoly``.

    Returns
    -------
    dict
        in `__geo_interface__` format
    """
    h3shape = cells_to_h3shape(cells, tight=tight)
    return h3shape_to_geo(h3shape)


def is_pentagon(h):
    """
    Identify if an H3 cell is a pentagon.

    Parameters
    ----------
    h : H3Index

    Returns
    -------
    bool
        ``True`` if input is a valid H3 cell which is a pentagon.

    Notes
    -----
    A pentagon should *also* pass ``is_valid_cell()``.
    Will return ``False`` for valid H3Edge.
    """
    return _cy.is_pentagon(_in_scalar(h))


def get_base_cell_number(h):
    """
    Return the base cell *number* (``0`` to ``121``) of the given cell.

    The base cell *number* and the H3Index are two different representations
    of the same cell: the parent cell of resolution ``0``.

    The base cell *number* is encoded within the corresponding
    H3Index.

    todo: could work with edges

    Parameters
    ----------
    h : H3Cell

    Returns
    -------
    int

    Examples
    --------
    >>> h = construct_cell(57, 2, 1, 4)
    >>> h
    '83728cfffffffff'
    >> get_base_cell_number(h)
    57
    """
    return _cy.get_base_cell_number(_in_scalar(h))


def get_index_digit(h, res):
    """
    Get the index digit of a cell at the given resolution.

    Parameters
    ----------
    h : H3Cell
        Cell whose index digit will be returned.
    res : int
        Resolution (``>= 1``) at which to read the digit.

    Returns
    -------
    int
        The index digit at the requested resolution.

    Examples
    --------
    >>> h = construct_cell(7, 2, 1, 4)
    >>> h
    '830e8cfffffffff'
    >>> get_index_digit(h, 1)
    2
    >>> get_index_digit(h, 2)
    1
    >>> get_index_digit(h, 3)
    4
    """
    return _cy.get_index_digit(_in_scalar(h), res)


def construct_cell(base_cell_number, *digits, res=None):
    """
    Construct cell from base cell and digits.

    Parameters
    ----------
    base_cell_number : int
        Base cell *number* (``0`` to ``121``).
    *digits : int
        Sequence of index digits (``0`` to ``6``).
        Length of digits will be the resulting resolution of the output cell.
    res : int, optional
        Resolution of the constructed cell. If provided, it must equal
        ``len(digits)``; otherwise it is inferred from the number of digits.

    Returns
    -------
    H3Cell
        The constructed cell.

    Examples
    --------
    >>> construct_cell(7, 2, 1, 4)  # resolution 3 cell
    '830e8cfffffffff'

    >>> construct_cell(15, 0, 0, 5, 3)  # resolution 4 cell
    '841e057ffffffff'

    >>> construct_cell(15, 0, 0, 5, 3, res=4)
    '841e057ffffffff'
    """
    if (res is not None) and (len(digits) != res):
        raise ValueError('Resolution must match number of digits.')

    digits = array('i', digits)
    o = _cy.construct_cell(base_cell_number, digits)
    return _out_scalar(o)


def deconstruct_cell(h):
    """
    Deconstruct cell into base cell and digits.

    Parameters
    ----------
    h : H3Cell
        Cell to deconstruct.

    Returns
    -------
    list of int
        [base_cell_number, digit1, digit2, ..., digitN]

    Examples
    --------
    >>> h = construct_cell(7, 2, 1, 4)  # resolution 3 cell
    >>> h
    '830e8cfffffffff'
    >>> deconstruct_cell(h)
    (7, 2, 1, 4)

    >>> h = construct_cell(15, 0, 0, 5, 3)  # resolution 4 cell
    >>> h
    '841e057ffffffff'
    >>> deconstruct_cell(h)
    (15, 0, 0, 5, 3)
    >>> construct_cell(*deconstruct_cell(h), 0) == cell_to_center_child(h)
    """
    res = get_resolution(h)
    bc = get_base_cell_number(h)
    digits = [get_index_digit(h, r + 1) for r in range(res)]

    return [bc, *digits]


def are_neighbor_cells(h1, h2):
    """
    Returns ``True`` if ``h1`` and ``h2`` are neighboring cells.

    Parameters
    ----------
    h1 : H3Cell
    h2 : H3Cell

    Returns
    -------
    bool
    """
    h1 = _in_scalar(h1)
    h2 = _in_scalar(h2)

    return _cy.are_neighbor_cells(h1, h2)


def cells_to_directed_edge(origin, destination):
    """
    Create an H3 Index denoting a unidirectional edge.

    The edge is constructed from neighboring cells ``origin`` and
    ``destination``.

    Parameters
    ----------
    origin : H3Cell
    destination : H3Cell

    Raises
    ------
    ValueError
        When cells are not adjacent.

    Returns
    -------
    H3Edge
    """
    o = _in_scalar(origin)
    d = _in_scalar(destination)
    e = _cy.cells_to_directed_edge(o, d)
    e = _out_scalar(e)

    return e


def get_directed_edge_origin(e):
    """
    Origin cell from an H3 directed edge.

    Parameters
    ----------
    e : H3Edge

    Returns
    -------
    H3Cell
    """
    e = _in_scalar(e)
    o = _cy.get_directed_edge_origin(e)
    o = _out_scalar(o)

    return o


def get_directed_edge_destination(e):
    """
    Destination cell from an H3 directed edge.

    Parameters
    ----------
    e : H3Edge

    Returns
    -------
    H3Cell
    """
    e = _in_scalar(e)
    d = _cy.get_directed_edge_destination(e)
    d = _out_scalar(d)

    return d


def directed_edge_to_cells(e):
    """
    Return (origin, destination) tuple from H3 directed edge

    Parameters
    ----------
    e : H3Edge

    Returns
    -------
    H3Cell
        Origin cell of edge
    H3Cell
        Destination cell of edge
    """
    e = _in_scalar(e)
    o, d = _cy.directed_edge_to_cells(e)
    o, d = _out_scalar(o), _out_scalar(d)

    return o, d


def origin_to_directed_edges(origin):
    """
    Return all directed edges starting from ``origin`` cell.

    Parameters
    ----------
    origin : H3Cell

    Returns
    -------
    unordered collection of H3Edge
    """
    mv = _cy.origin_to_directed_edges(_in_scalar(origin))

    return _out_collection(mv)


def directed_edge_to_boundary(edge):
    """
    Returns points representing the edge (line of points
    describing the boundary between two cells).

    Parameters
    ----------
    edge : H3Edge

    Returns
    -------
    tuple of (lat, lng) tuples
    """
    return _cy.directed_edge_to_boundary(_in_scalar(edge))


def grid_path_cells(start, end):
    """
    Returns the ordered collection of cells denoting a
    minimum-length non-unique path between cells.

    Parameters
    ----------
    start : H3Cell
    end : H3Cell

    Returns
    -------
    ordered collection of H3Cell
        Starting with ``start``, and ending with ``end``.
    """
    mv = _cy.grid_path_cells(_in_scalar(start), _in_scalar(end))

    return _out_collection(mv)


def is_res_class_III(h):
    """
    Determine if cell has orientation "Class II" or "Class III".

    The orientation of pentagons/hexagons on the icosahedron can be one
    of two types: "Class II" or "Class III".

    All cells within a resolution have the same type, and the type
    alternates between resolutions.

    "Class II" cells have resolutions:  0,2,4,6,8,10,12,14
    "Class III" cells have resolutions: 1,3,5,7,9,11,13,15

    Parameters
    ----------
    h : H3Cell

    Returns
    -------
    bool
        ``True`` if ``h`` is "Class III".
        ``False`` if ``h`` is "Class II".

    References
    ----------
    1. https://uber.github.io/h3/#/documentation/core-library/coordinate-systems
    """
    return _cy.is_res_class_iii(_in_scalar(h))


def get_pentagons(res):
    """
    Return all pentagons at a given resolution.

    Parameters
    ----------
    res : int
        Resolution of the pentagons

    Returns
    -------
    unordered collection of H3Cell
    """
    mv = _cy.get_pentagons(res)

    return _out_collection(mv)


def get_res0_cells():
    """
    Return all cells at resolution 0.

    Parameters
    ----------
    None

    Returns
    -------
    unordered collection of H3Cell

    Notes
    -----
    There is currently no guaranteed order of the output cells.
    """
    mv = _cy.get_res0_cells()

    return _out_collection(mv)


def cell_to_center_child(h, res=None):
    """
    Get the center child of a cell at some finer resolution.

    Parameters
    ----------
    h : H3Cell
    res : int or None, optional
        The resolution for the child cell
        If ``None``, then ``res = resolution(h) + 1``

    Returns
    -------
    H3Cell
    """
    h = _in_scalar(h)
    p = _cy.cell_to_center_child(h, res)
    p = _out_scalar(p)

    return p


def get_icosahedron_faces(h):
    """
    Return icosahedron faces intersecting a given H3 cell.

    There are twenty possible faces, ranging from 0--19.

    Note: Every interface returns a Python ``set`` of ``int``.

    Parameters
    ----------
    h : H3Cell

    Returns
    -------
    Python ``set`` of ``int``
    """
    h = _in_scalar(h)
    faces = _cy.get_icosahedron_faces(h)

    return faces


def cell_to_local_ij(origin, h):
    """
    Return local (i,j) coordinates of cell ``h`` in relation to ``origin`` cell


    Parameters
    ----------
    origin : H3Cell
        Origin/central cell for defining i,j coordinates.
    h: H3Cell
        Destination cell whose i,j coordinates we'd like, based off
        of the origin cell.


    Returns
    -------
    Tuple (i, j) of integer local coordinates of cell ``h``


    Notes
    -----

    The ``origin`` cell does not define (0, 0) for the IJ coordinate space.
    (0, 0) refers to the center of the base cell containing origin at the
    resolution of `origin`.
    Subtracting the IJ coordinates of ``origin`` from every cell would get
    you the property of (0, 0) being the ``origin``.

    This is done so we don't need to keep recomputing the coordinates of
    ``origin`` if not needed.
    """
    origin = _in_scalar(origin)
    h = _in_scalar(h)

    i, j = _cy.cell_to_local_ij(origin, h)

    return i, j


def local_ij_to_cell(origin, i, j):
    """
    Return cell at local (i,j) position relative to the ``origin`` cell.

    Parameters
    ----------
    origin : H3Cell
        Origin/central cell for defining i,j coordinates.
    i, j: int
        Integer coordinates with respect to ``origin`` cell.


    Returns
    -------
    H3Cell at local (i,j) position relative to the ``origin`` cell


    Notes
    -----

    The ``origin`` cell does not define (0, 0) for the IJ coordinate space.
    (0, 0) refers to the center of the base cell containing origin at the
    resolution of ``origin``.
    Subtracting the IJ coordinates of ``origin`` from every cell would get
    you the property of (0, 0) being the ``origin``.

    This is done so we don't need to keep recomputing the coordinates of
    ``origin`` if not needed.
    """
    origin = _in_scalar(origin)

    h = _cy.local_ij_to_cell(origin, i, j)
    h = _out_scalar(h)

    return h


def cell_area(h, unit='km^2'):
    """
    Compute the spherical surface area of a specific H3 cell.

    Parameters
    ----------
    h : H3Cell
    unit: str
        Unit for area result (``'km^2'``, ``'m^2'``, or ``'rads^2'``)


    Returns
    -------
    The area of the H3 cell in the given units


    Notes
    -----
    This function breaks the cell into spherical triangles, and computes
    their spherical area.
    The function uses the spherical distance calculation given by
    ``great_circle_distance()``.
    """
    h = _in_scalar(h)

    return _cy.cell_area(h, unit=unit)


def edge_length(e, unit='km'):
    """
    Compute the spherical length of a specific H3 edge.

    Parameters
    ----------
    h : H3Cell
    unit: str
        Unit for length result (``'km'``, ``'m'``, or ``'rads'``)


    Returns
    -------
    The length of the edge in the given units


    Notes
    -----
    This function uses the spherical distance calculation given by
    ``great_circle_distance()``.
    """
    e = _in_scalar(e)

    return _cy.edge_length(e, unit=unit)


def great_circle_distance(latlng1, latlng2, unit='km'):
    """
    Compute the spherical distance between two (lat, lng) points.
    AKA: great circle distance or "haversine" distance.

    todo: overload to allow two cell inputs?

    Parameters
    ----------
    latlng1 : tuple
        (lat, lng) tuple in degrees
    latlng2 : tuple
        (lat, lng) tuple in degrees
    unit: str
        Unit for distance result (``'km'``, ``'m'``, or ``'rads'``)

    Returns
    -------
    The spherical distance between the points in the given units
    """
    lat1, lng1 = latlng1
    lat2, lng2 = latlng2
    return _cy.great_circle_distance(
        lat1, lng1,
        lat2, lng2,
        unit = unit
    )


def cell_to_vertex(h, vertex_num):
    """
    Return a (specified) vertex of an H3 cell.

    Parameters
    ----------
    h : H3Cell
    vertex_num : int
        Vertex number (0-5)

    Returns
    -------
    The vertex
    """
    h = _in_scalar(h)
    h = _cy.cell_to_vertex(h, vertex_num)
    return _out_scalar(h)


def cell_to_vertexes(h):
    """
    Return a list of vertexes of an H3 cell.
    The list will be of length 5 for pentagons and 6 for hexagons.

    Parameters
    ----------
    h : H3Cell

    Returns
    -------
    A list of vertexes
    """
    h = _in_scalar(h)
    mv = _cy.cell_to_vertexes(h)
    return _out_collection(mv)


def vertex_to_latlng(v):
    """
    Return latitude and longitude of a vertex.

    Returns
    -------
    lat : float
        Latitude
    lng : float
        Longitude
    """
    v = _in_scalar(v)
    return _cy.vertex_to_latlng(v)


def is_valid_vertex(v):
    """
    Validates an H3 vertex.

    Returns
    -------
    bool
    """
    try:
        v = _in_scalar(v)
        return _cy.is_valid_vertex(v)
    except (ValueError, TypeError):
        return False
