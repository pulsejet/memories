"""
Coordinate accessors for timezonefinder.

This module provides classes for accessing polygon coordinates
either directly from file or from preloaded memory.
"""

from abc import ABC, abstractmethod
import mmap
from pathlib import Path
from typing import Dict

import numpy as np

from timezonefinder import utils
from timezonefinder.flatbuf.generated.polygons.PolygonCollection import (
    PolygonCollection,
)
from timezonefinder.flatbuf.io.polygons import (
    get_polygon_collection,
    read_polygon_array_from_binary,
)


class AbstractCoordAccessor(ABC):
    """Abstract base class defining the interface for coordinate accessors."""

    @abstractmethod
    def __init__(self, coordinate_file_path: Path):
        """
        Initialize the coordinate accessor.

        Args:
            coordinate_file_path: Path to the coordinate file
        """
        pass

    @abstractmethod
    def __getitem__(self, idx: int) -> np.ndarray:
        """
        Get the polygon coordinates for the given index.

        Args:
            idx: The polygon index

        Returns:
            A numpy array containing the polygon coordinates
        """
        pass

    def __del__(self):
        """
        Ensure resources are cleaned up when the object is destroyed.
        """
        self.cleanup()

    @abstractmethod
    def cleanup(self) -> None:
        """Clean up resources."""
        pass


class FileCoordAccessor(AbstractCoordAccessor):
    """Accessor that reads polygon coordinates from the file on demand."""

    def __init__(self, coordinate_file_path: Path):
        """
        Initialize the file-based coordinate accessor.

        Args:
            coordinate_file_path: Path to the coordinate file
        """
        self.coordinate_file_path = coordinate_file_path
        # Initialize file resources using proper resource management.
        try:
            # Use memory-mapped file for on-demand reading
            self.coord_file: object = open(self.coordinate_file_path, "rb")
            # Create memory map
            self.coord_buf: mmap.mmap = mmap.mmap(
                self.coord_file.fileno(), 0, access=mmap.ACCESS_READ
            )
            self.polygon_collection: PolygonCollection = get_polygon_collection(
                self.coord_buf
            )
        except Exception:
            # Clean up any partially initialized resources
            self.cleanup()
            raise

    def __getitem__(self, idx: int) -> np.ndarray:
        """
        Get the polygon coordinates for the given index.

        Args:
            idx: The polygon index

        Returns:
            A numpy array containing the polygon coordinates
        """
        return read_polygon_array_from_binary(self.polygon_collection, idx)

    def cleanup(self) -> None:
        """Clean up resources."""
        utils.close_resource(self.coord_file)
        utils.close_resource(self.coord_buf)
        del self.polygon_collection


class MemoryCoordAccessor(AbstractCoordAccessor):
    """Accessor that preloads all polygon coordinates into memory."""

    def __init__(self, coordinate_file_path: Path):
        """
        Initialize the memory-based coordinate accessor.

        Args:
            coordinate_file_path: Path to the coordinate file
        """
        # Read entire file into memory
        with open(coordinate_file_path, "rb") as f:
            coord_buf = f.read()

        # Initialize polygon collection
        polygon_collection = get_polygon_collection(coord_buf)

        # Get number of polygons
        num_polygons = polygon_collection.PolygonsLength()

        # Preload all polygons
        self.polygons: Dict[int, np.ndarray] = {}
        for idx in range(num_polygons):
            self.polygons[idx] = read_polygon_array_from_binary(polygon_collection, idx)

        # Once polygons are loaded, we don't need to keep polygon_collection or coord_buf references
        # They'll be garbage collected

    def __getitem__(self, idx: int) -> np.ndarray:
        """
        Get the polygon coordinates for the given index.

        Args:
            idx: The polygon index

        Returns:
            A numpy array containing the polygon coordinates
        """
        return self.polygons[idx]

    def cleanup(self) -> None:
        """Clean up resources."""
        del self.polygons
        # Just clear the dictionary, no file resources to clean up
        if hasattr(self, "polygons"):
            self.polygons.clear()


def create_coord_accessor(
    coordinate_file_path: Path, in_memory: bool
) -> AbstractCoordAccessor:
    """
    Factory function to create the appropriate coordinate accessor.

    Args:
        coordinate_file_path: Path to the coordinate file
        in_memory: Whether to use in-memory mode

    Returns:
        An instance of a coordinate accessor
    """
    if in_memory:
        return MemoryCoordAccessor(coordinate_file_path)
    else:
        return FileCoordAccessor(coordinate_file_path)
