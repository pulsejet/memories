from .h3lib cimport bool, int64_t, H3int

cpdef bool is_valid_index(H3int h)
cpdef bool is_valid_cell(H3int h)
cpdef bool is_pentagon(H3int h)
cpdef int get_base_cell_number(H3int h) except -1
cpdef int get_resolution(H3int h) except -1
cpdef int get_index_digit(H3int h, int res) except -1
cpdef H3int construct_cell(int baseCellNumber, const int[:] digits) except 0
cpdef int grid_distance(H3int h1, H3int h2) except -1
cpdef H3int[:] grid_disk(H3int h, int k)
cpdef H3int[:] grid_ring(H3int h, int k)
cpdef H3int cell_to_parent(H3int h, res=*) except 0
cpdef int64_t cell_to_children_size(H3int h, res=*) except -1
cpdef H3int[:] cell_to_children(H3int h, res=*)
cpdef H3int cell_to_center_child(H3int h, res=*) except 0
cpdef int64_t cell_to_child_pos(H3int child, int parent_res) except -1
cpdef H3int child_pos_to_cell(H3int parent, int child_res, int64_t child_pos) except 0
cpdef H3int[:] compact_cells(const H3int[:] hu)
cpdef H3int[:] uncompact_cells(const H3int[:] hc, int res)
cpdef int64_t get_num_cells(int resolution) except -1
cpdef double average_hexagon_area(int resolution, unit=*) except -1
cpdef double cell_area(H3int h, unit=*) except -1
cpdef H3int[:] grid_path_cells(H3int start, H3int end)
cpdef bool is_res_class_iii(H3int h)
cpdef H3int[:] get_pentagons(int res)
cpdef H3int[:] get_res0_cells()
cpdef get_icosahedron_faces(H3int h)
cpdef (int, int) cell_to_local_ij(H3int origin, H3int h) except *
cpdef H3int local_ij_to_cell(H3int origin, int i, int j) except 0
