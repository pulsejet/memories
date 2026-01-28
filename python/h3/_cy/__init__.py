# flake8: noqa

"""
This module should serve as the interface between the C/Cython code and
the Python code. That is, it is an internal API.
This module should import all the Cython functions we
intend to expose to be used in pure Python code, and each of the H3-py
APIs should *only* reference functions and symbols listed here.

These functions should handle input validation, guard against the
possibility of segfaults, raise appropriate errors, and handle memory
management. The API wrapping code around this should focus on the cosmetic
function interface and input conversion (string to int, for instance).
"""

from .cells import (
    is_valid_index,
    is_valid_cell,
    is_pentagon,
    get_base_cell_number,
    get_resolution,
    get_index_digit,
    construct_cell,
    cell_to_parent,
    grid_distance,
    grid_disk,
    grid_ring,
    cell_to_children_size,
    cell_to_children,
    cell_to_child_pos,
    child_pos_to_cell,
    compact_cells,
    uncompact_cells,
    get_num_cells,
    average_hexagon_area,
    cell_area,
    grid_path_cells,
    is_res_class_iii,
    get_pentagons,
    get_res0_cells,
    cell_to_center_child,
    get_icosahedron_faces,
    cell_to_local_ij,
    local_ij_to_cell,
)

from .edges import (
    are_neighbor_cells,
    cells_to_directed_edge,
    is_valid_directed_edge,
    get_directed_edge_origin,
    get_directed_edge_destination,
    directed_edge_to_cells,
    origin_to_directed_edges,
    average_hexagon_edge_length,
    edge_length,
)

from .latlng import (
    latlng_to_cell,
    cell_to_latlng,
    polygon_to_cells,
    polygons_to_cells,
    polygon_to_cells_experimental,
    polygons_to_cells_experimental,
    cell_to_boundary,
    directed_edge_to_boundary,
    great_circle_distance,
)

from .vertex import (
    cell_to_vertex,
    cell_to_vertexes,
    vertex_to_latlng,
    is_valid_vertex,
)

from .to_multipoly import (
    cells_to_multi_polygon
)

from .util import (
    c_version,
    str_to_int,
    int_to_str,
)

from .memory import (
    iter_to_mv,
)

from .error_system import (
    UnknownH3ErrorCode,
    H3BaseException,

    H3GridNavigationError,
    H3MemoryError,
    H3ValueError,

    H3FailedError,
    H3DomainError,
    H3LatLngDomainError,
    H3ResDomainError,
    H3CellInvalidError,
    H3DirEdgeInvalidError,
    H3UndirEdgeInvalidError,
    H3VertexInvalidError,
    H3PentagonError,
    H3DuplicateInputError,
    H3NotNeighborsError,
    H3ResMismatchError,
    H3MemoryAllocError,
    H3MemoryBoundsError,
    H3OptionInvalidError,
    H3IndexInvalidError,
    H3BaseCellDomainError,
    H3DigitDomainError,
    H3DeletedDigitError,

    get_H3_ERROR_END,
    error_code_to_exception,
)
