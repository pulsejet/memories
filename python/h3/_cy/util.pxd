from .h3lib cimport H3int, H3str, LatLng

cdef LatLng deg2coord(double lat, double lng) nogil
cdef (double, double) coord2deg(LatLng c) nogil

cpdef H3int str_to_int(H3str h) except? 0
cpdef H3str int_to_str(H3int x)

cdef check_cell(H3int h)
cdef check_edge(H3int e)
cdef check_vertex(H3int v)
cdef check_index(H3int h)
cdef check_res(int res)
cdef check_distance(int k)
