"""performance critical utility functions

JIT compiled for efficiency in case `numba` is installed

TODO Numba Ahead-Of-Time Compilation:
cc = CC('precompiled_helpers', )
# Uncomment the following line to print out the compilation steps
cc.verbose = True

if __name__ == "__main__":
    cc.compile()
"""

import numpy as np

from timezonefinder.configs import (
    COORD2INT_FACTOR,
    INT2COORD_FACTOR,
    CoordLists,
    CoordPairs,
)

try:
    from numba import njit, boolean, i4, f8
    from numba.types import Array

    using_numba = True
except ImportError:
    using_numba = False
    # replace Numba functionality with "transparent" implementations
    from timezonefinder._numba_replacements import njit, boolean, Array, i4, f8


# For Fortran-ordered arrays (F-contiguous), use the 'order="F"' in the Numba signature
# F order is natural for the used coordinate schema:
# coords = [x_coords, y_coords]
# x_coords = coords[0]
CoordType = Array(i4, 2, "F", True, aligned=True)


# @cc.export('inside_polygon', 'b1(i4, i4, i4[:, :])')
@njit(boolean(i4, i4, CoordType), cache=True)
def pt_in_poly_python(x: int, y: int, coords: np.ndarray) -> bool:
    """
    Implementing the ray casting point in polygon test algorithm
    cf. https://en.wikipedia.org/wiki/Point_in_polygon#Ray_casting_algorithm
    :param x:
    :param y:
    :param coords: a polygon represented by a list containing two lists (x and y coordinates):
        [ [x1,x2,x3...], [y1,y2,y3...]]
        those lists are actually numpy arrays which are being read directly from a binary file
    :return: true if the point (x,y) lies within the polygon

    Some overflow considerations for the critical part of comparing the line segment slopes:

        (y2 - y) * (x2 - x1) <= delta_y_max * delta_x_max
        (y2 - y1) * (x2 - x) <= delta_y_max * delta_x_max
        delta_y_max * delta_x_max = 180 * 360 < 65 x10^3

    Instead of calculating with float I decided using just ints (by multiplying with 10^7). That gives us:

        delta_y_max * delta_x_max = 180x10^7 * 360x10^7
        delta_y_max * delta_x_max <= 65x10^17

    So these numbers need up to log_2(65 x10^17) ~ 63 bits to be represented! Even though values this big should never
     occur in practice (timezone polygons do not span the whole lng lat coordinate space),
     32bit accuracy hence is not safe to use here!
     pure Python automatically uses the appropriate int data type preventing overflow
     (cf. https://www.python.org/dev/peps/pep-0237/),
     but here the data types are numpy internal static data types. The data is stored as int32
     -> use int64 when comparing slopes!

    slower naive implementation:
    j = nr_coords - 1
    for i in range(nr_coords):
        if ((y_coords[i] > y) != (y_coords[j] > y)) and (
                x
                < (int64(x_coords[j]) - int64(x_coords[i]))
                * (int64(y) - int64(y_coords[i]))
                / (int64(y_coords[j]) - int64(y_coords[i]))
                + int64(x_coords[i])
        ):
            inside = not inside
        j = i
        i += 1
    """
    x_coords = coords[0]
    y_coords = coords[1]
    nr_coords = len(x_coords)
    inside = False

    # the edge from the last to the first point is checked first
    y1 = y_coords[-1]
    y_gt_y1 = y > y1
    for i in range(nr_coords):
        y2 = y_coords[i]
        y_gt_y2 = y > y2
        if y_gt_y1 ^ y_gt_y2:  # XOR
            # [p1-p2] crosses horizontal line in p
            x1 = x_coords[i - 1]
            x2 = x_coords[i]
            # only count crossings "right" of the point ( >= x)
            x_le_x1 = x <= x1
            x_le_x2 = x <= x2
            if x_le_x1 or x_le_x2:
                if x_le_x1 and x_le_x2:
                    # p1 and p2 are both to the right -> valid crossing
                    inside = not inside
                else:
                    # compare the slope of the line [p1-p2] and [p-p2]
                    # depending on the position of p2 this determines whether
                    # the polygon edge is right or left of the point
                    # to avoid expensive division the divisors (of the slope dy/dx) are brought to the other side
                    # ( dy/dx > a  ==  dy > a * dx )
                    # only one of the points is to the right
                    # NOTE: int64 precision required to prevent overflow
                    y_64 = np.int64(y)
                    y1_64 = np.int64(y1)
                    y2_64 = np.int64(y2)
                    x_64 = np.int64(x)
                    x1_64 = np.int64(x1)
                    x2_64 = np.int64(x2)
                    slope1 = (y2_64 - y_64) * (x2_64 - x1_64)
                    slope2 = (y2_64 - y1_64) * (x2_64 - x_64)
                    # NOTE: accept slope equality to also detect if p lies directly on an edge
                    if y_gt_y1:
                        if slope1 <= slope2:
                            inside = not inside
                    elif slope1 >= slope2:  # NOT y_gt_y1
                        inside = not inside

        # next point
        y1 = y2
        y_gt_y1 = y_gt_y2

    return inside


@njit(cache=True)
def get_last_change_idx(lst: np.ndarray) -> int:
    """
    :param lst: list of entries
    :return: returns the index to the element for which all following elements are equal
    """
    nr_entries = lst.shape[0]
    if nr_entries <= 1:
        return 0
    # at least 2 elements
    last_elem = lst[-1]
    for ptr in range(2, nr_entries + 1):
        # Note: from the back
        element = lst[-ptr]
        if element != last_elem:
            # return the last pointer value
            # Attention: convert into positive "absolute" index first
            return nr_entries - ptr + 1
    # Note: all entries are the same -> ptr will be 0
    return 0


# @cc.export('int2coord', f8(i4))
@njit(f8(i4), cache=True)
def int2coord(i4: int) -> float:
    return float(i4 * INT2COORD_FACTOR)


# @cc.export('coord2int', i4(f8))
@njit(i4(f8), cache=True)
def coord2int(double: float) -> int:
    return int(double * COORD2INT_FACTOR)


@njit(cache=True)
def convert2coords(polygon_data: np.ndarray) -> CoordLists:
    # return a tuple of coordinate lists
    return [
        [int2coord(x) for x in polygon_data[0]],
        [int2coord(y) for y in polygon_data[1]],
    ]


@njit(cache=True)
def convert2coord_pairs(polygon_data: np.ndarray) -> CoordPairs:
    # return a list of coordinate tuples (x,y)
    x_coords = polygon_data[0]
    y_coords = polygon_data[1]
    nr_coords = len(x_coords)
    coodinate_list = [
        (int2coord(x_coords[i]), int2coord(y_coords[i])) for i in range(nr_coords)
    ]
    return coodinate_list


@njit(boolean(f8), cache=True)
def is_valid_lat(lat: float) -> bool:
    return -90.0 <= lat <= 90.0


@njit(boolean(f8), cache=True)
def is_valid_lng(lng: float) -> bool:
    return -180.0 <= lng <= 180.0
