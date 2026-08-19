from pathlib import Path
from typing import Iterable, Union

import numpy as np

from timezonefinder.configs import IntegerLike

from timezonefinder import utils
from timezonefinder.coord_accessors import AbstractCoordAccessor, create_coord_accessor
from timezonefinder.flatbuf.io.polygons import (
    get_coordinate_path,
)
from timezonefinder.np_binary_helpers import (
    get_xmax_path,
    get_xmin_path,
    get_ymax_path,
    get_ymin_path,
    read_per_polygon_vector,
)


class PolygonArray:
    xmin: np.ndarray
    xmax: np.ndarray
    ymin: np.ndarray
    ymax: np.ndarray
    coordinates: AbstractCoordAccessor

    def __init__(
        self,
        data_location: Union[str, Path],
        in_memory: bool = False,
    ):
        """
        Initialize the PolygonArray.
        :param data_location: The path to the binary data files to use.
        :param in_memory: Whether to completely read and keep the coordinate data in memory as numpy.
        """
        self.in_memory = in_memory
        self.data_location: Path = Path(data_location)

        xmin_path = get_xmin_path(self.data_location)
        xmax_path = get_xmax_path(self.data_location)
        ymin_path = get_ymin_path(self.data_location)
        ymax_path = get_ymax_path(self.data_location)

        # read all per polygon vectors directly into memory (no matter the memory mode)
        self.xmin = read_per_polygon_vector(xmin_path)
        self.xmax = read_per_polygon_vector(xmax_path)
        self.ymin = read_per_polygon_vector(ymin_path)
        self.ymax = read_per_polygon_vector(ymax_path)

        coordinate_file_path = get_coordinate_path(self.data_location)
        # Initialize the appropriate coordinate accessor based on memory mode
        self.coordinates = create_coord_accessor(coordinate_file_path, self.in_memory)

    def __del__(self):
        """Clean up resources when the object is destroyed."""
        del self.coordinates
        del self.xmin
        del self.xmax
        del self.ymin
        del self.ymax

    def __len__(self) -> int:
        """
        Get the number of polygons in the collection.
        :return: Number of polygons
        """
        return len(self.xmin)

    def outside_bbox(self, poly_id: IntegerLike, x: int, y: int) -> bool:
        """
        Check if a point is outside the bounding box of a polygon.

        :param poly_id: Polygon ID
        :param x: X-coordinate of the point
        :param y: Y-coordinate of the point
        :return: True if the point is outside the boundaries, False otherwise
        """
        if x > self.xmax[poly_id]:
            return True
        if x < self.xmin[poly_id]:
            return True
        if y > self.ymax[poly_id]:
            return True
        if y < self.ymin[poly_id]:
            return True
        return False

    def coords_of(self, idx: IntegerLike) -> np.ndarray:
        """
        Get the polygon coordinates for the given index.

        Args:
            idx: The polygon index

        Returns:
            A numpy array containing the polygon coordinates
        """
        return self.coordinates[idx]

    def pip(self, poly_id: IntegerLike, x: int, y: int) -> bool:
        """
        Point in polygon (PIP) test.

        :param poly_id: Polygon ID
        :param x: X-coordinate of the point
        :param y: Y-coordinate of the point
        :return: True if the point is inside the polygon, False otherwise
        """
        polygon = self.coords_of(poly_id)
        return utils.inside_polygon(x, y, polygon)

    def pip_with_bbox_check(self, poly_id: IntegerLike, x: int, y: int) -> bool:
        """
        Point in polygon (PIP) test with bounding box check.

        :param poly_id: Polygon ID
        :param x: X-coordinate of the point
        :param y: Y-coordinate of the point
        :return: True if the point is inside the polygon, False otherwise
        """
        if self.outside_bbox(poly_id, x, y):
            return False
        return self.pip(poly_id, x, y)

    def in_any_polygon(self, poly_ids: Iterable[int], x: int, y: int) -> bool:
        """
        Check if a point is inside any of the specified polygons.

        :param poly_ids: An iterable of polygon IDs
        :param x: X-coordinate of the point
        :param y: Y-coordinate of the point
        :return: True if the point is inside any polygon, False otherwise
        """
        for poly_id in poly_ids:
            if self.pip_with_bbox_check(poly_id, x, y):
                return True
        return False
