from .h3lib cimport bool, H3int

cpdef bool are_neighbor_cells(H3int h1, H3int h2)
cpdef H3int cells_to_directed_edge(H3int origin, H3int destination) except *
cpdef bool is_valid_directed_edge(H3int e)
cpdef H3int get_directed_edge_origin(H3int e) except 1
cpdef H3int get_directed_edge_destination(H3int e) except 1
cpdef (H3int, H3int) directed_edge_to_cells(H3int e) except *
cpdef H3int[:] origin_to_directed_edges(H3int origin)
cpdef double average_hexagon_edge_length(int resolution, unit=*) except -1
cpdef double edge_length(H3int e, unit=*) except -1
