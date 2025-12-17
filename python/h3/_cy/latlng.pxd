from .h3lib cimport H3int

cpdef H3int latlng_to_cell(double lat, double lng, int res) except 1
cpdef (double, double) cell_to_latlng(H3int h) except *
cpdef double great_circle_distance(
    double lat1, double lng1,
    double lat2, double lng2, unit=*) except -1
