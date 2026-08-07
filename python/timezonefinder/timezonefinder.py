import json
from abc import ABC, abstractmethod
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Tuple, Union
import numpy as np
from h3.api import numpy_int as h3

from timezonefinder.np_binary_helpers import (
    get_zone_ids_path,
    get_zone_positions_path,
    read_per_polygon_vector,
)
from timezonefinder.polygon_array import PolygonArray
from timezonefinder import utils, utils_clang
from timezonefinder.configs import (
    DEFAULT_DATA_DIR,
    SHORTCUT_H3_RES,
    CoordLists,
    CoordPairs,
    IntegerLike,
)

from timezonefinder.flatbuf.io.hybrid_shortcuts import (
    get_hybrid_shortcut_file_path,
    read_hybrid_shortcuts_binary,
)
from timezonefinder.zone_names import read_zone_names


class AbstractTimezoneFinder(ABC):
    # prevent dynamic attribute assignment (-> safe memory)
    """
    Abstract base class for TimezoneFinder instances
    """

    __slots__ = [
        "data_location",
        "shortcut_mapping",
        "in_memory",
        "_fromfile",
        "timezone_names",
        "zone_ids",
        "holes_dir",
        "boundaries_dir",
        "boundaries",
        "holes",
    ]

    zone_ids: np.ndarray
    shortcut_mapping: Dict[int, Union[int, np.ndarray]]
    """
    List of attribute names that store opened binary data files.
    """

    def __init__(
        self,
        bin_file_location: Optional[Union[str, Path]] = None,
        in_memory: bool = False,
    ):
        """
        Initialize the AbstractTimezoneFinder.
        :param bin_file_location: The path to the binary data files to use. If None, uses native package data.
        :param in_memory: ignored. All binary files will be read into memory (few MB). Only used for polygon coordinate data.
        """
        if bin_file_location is None:
            bin_file_location = DEFAULT_DATA_DIR
        self.data_location: Path = Path(bin_file_location)

        self.timezone_names = read_zone_names(self.data_location)

        # Load hybrid shortcut file - contains both zone IDs (for unique zones) and polygon arrays (for ambiguous zones)
        zone_ids_path = get_zone_ids_path(self.data_location)
        zone_ids_temp = read_per_polygon_vector(zone_ids_path)
        zone_id_dtype = zone_ids_temp.dtype

        path2shortcut = get_hybrid_shortcut_file_path(zone_id_dtype, self.data_location)
        self.shortcut_mapping = read_hybrid_shortcuts_binary(path2shortcut)

        zone_ids_path = get_zone_ids_path(self.data_location)
        self.zone_ids = read_per_polygon_vector(zone_ids_path)

    def _iter_boundary_ids_of_zone(self, zone_id: int) -> Iterable[int]:
        """
        Yield the boundary polygon IDs for a given zone ID.

        :param zone_id: ID of the zone
        :yield: boundary polygon IDs
        """
        # load only on demand. used when shortcuts contain zone IDs (hybrid optimization)
        zone_positions_path = get_zone_positions_path(self.data_location)
        zone_positions = np.load(zone_positions_path, mmap_mode="r")
        first_boundary_id_zone = zone_positions[zone_id]
        # read the id of the first boundary polygon of the consequent zone
        # NOTE: this has also been added for the last zone
        first_boundary_id_next = zone_positions[zone_id + 1]
        yield from range(first_boundary_id_zone, first_boundary_id_next)

    @property
    def nr_of_zones(self) -> int:
        """
        Get the number of timezones.

        :rtype: int
        """
        return len(self.timezone_names)

    @staticmethod
    def using_numba() -> bool:
        """
        Check if Numba is being used.

        :rtype: bool
        :return: True if Numba is being used to JIT compile helper functions
        """
        return utils.using_numba

    @staticmethod
    def using_clang_pip() -> bool:
        """
        :return: True if the compiled C implementation of the point in polygon algorithm is being used
        """
        return utils.inside_polygon == utils_clang.pt_in_poly_clang

    def zone_id_of(self, boundary_id: IntegerLike) -> int:
        """
        Get the zone ID of a polygon.

        :param boundary_id: The ID of the polygon.
        :type boundary_id: int
        :rtype: int
        """
        try:
            return int(self.zone_ids[boundary_id])
        except TypeError:
            raise ValueError(f"zone_ids is not set in directory {self.data_location}.")

    def zone_ids_of(self, boundary_ids: np.ndarray) -> np.ndarray:
        """
        Get the zone IDs of multiple boundary polygons.

        :param boundary_ids: An array of boundary polygon IDs.
        :return: array of corresponding timezone IDs.
        """
        return self.zone_ids[boundary_ids]

    def zone_name_from_id(self, zone_id: int) -> str:
        """
        Get the zone name from a zone ID.

        :param zone_id: The ID of the zone.
        :return: The name of the zone.
        :raises ValueError: If the timezone could not be found.
        """
        try:
            return self.timezone_names[zone_id]
        except IndexError:
            raise ValueError("timezone could not be found. index error.")

    def zone_name_from_boundary_id(self, boundary_id: IntegerLike) -> str:
        """
        Get the zone name from a boundary polygon ID.

        :param boundary_id: The ID of the boundary polygon.
        :return: The name of the zone.
        """
        zone_id = self.zone_id_of(boundary_id)
        return self.zone_name_from_id(zone_id)

    def _iter_boundaries_in_shortcut(self, *, lng: float, lat: float) -> Iterable[int]:
        """
        Iterate over boundary polygon IDs in the shortcut corresponding to the given coordinates.

        :param lng: The longitude of the point in degrees (-180.0 to 180.0).
        :param lat: The latitude of the point in degrees (90.0 to -90.0).
        :yield: Boundary polygon IDs.
        """
        hex_id = h3.latlng_to_cell(lat, lng, SHORTCUT_H3_RES)

        # Handle shortcuts (hybrid structure) - if it's a zone ID, get all polygons for that zone
        shortcut_value = self.shortcut_mapping.get(hex_id)
        if shortcut_value is None:
            return
        elif isinstance(shortcut_value, int):
            # Zone ID - get all boundary polygons for this zone
            # Most polygons will be quickly ruled out by bbox check
            yield from self._iter_boundary_ids_of_zone(shortcut_value)
        else:
            # Polygon array
            yield from shortcut_value

    @abstractmethod
    def timezone_at(self, *, lng: float, lat: float) -> Optional[str]:
        """looks up in which timezone the given coordinate is included in

        :param lng: longitude of the point in degree (-180.0 to 180.0)
        :param lat: latitude in degree (90.0 to -90.0)
        :return: the timezone name of a matching polygon or None
        """
        ...

    def timezone_at_land(self, *, lng: float, lat: float) -> Optional[str]:
        """computes in which land timezone a point is included in

        Especially for large polygons it is expensive to check if a point is really included.
        To speed things up there are "shortcuts" being used (stored in a binary file),
        which have been precomputed and store which timezone polygons have to be checked.

        :param lng: longitude of the point in degree (-180.0 to 180.0)
        :param lat: latitude in degree (90.0 to -90.0)
        :return: the timezone name of a matching polygon or
            ``None`` when an ocean timezone ("Etc/GMT+-XX") has been matched.
        """
        tz_name = self.timezone_at(lng=lng, lat=lat)
        if tz_name is not None and utils.is_ocean_timezone(tz_name):
            return None
        return tz_name

    def unique_timezone_at(self, *, lng: float, lat: float) -> Optional[str]:
        """returns the name of a unique zone within the corresponding shortcut

        :param lng: longitude of the point in degree (-180.0 to 180.0)
        :param lat: latitude in degree (90.0 to -90.0)
        :return: the timezone name of the unique zone or ``None`` if there are no or multiple zones in this shortcut
        """
        lng, lat = utils.validate_coordinates(lng, lat)
        hex_id = h3.latlng_to_cell(lat, lng, SHORTCUT_H3_RES)

        # Shortcuts behavior (hybrid structure with precomputed uniqueness)
        shortcut_value = self.shortcut_mapping.get(hex_id)
        if shortcut_value is None:
            return None
        elif isinstance(shortcut_value, int):
            # Zone ID - this is a precomputed unique zone
            unique_id = shortcut_value
        else:
            # Polygon array - by definition not unique (would be stored as int if unique)
            return None

        return self.zone_name_from_id(unique_id)

    def cleanup(self) -> None:
        """Clean up resources. Override in subclasses as needed."""
        pass

    def __enter__(self):
        """Enter the runtime context for the TimezoneFinder."""
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        """Exit the runtime context and clean up resources."""
        self.cleanup()
        return False


class TimezoneFinderL(AbstractTimezoneFinder):
    """a 'light' version of the TimezoneFinder class for quickly suggesting a timezone for a point on earth

    Instead of using timezone polygon data like ``TimezoneFinder``,
    this class only uses a precomputed 'shortcut' to suggest a probable result:
    the most common zone in a rectangle of a half degree of latitude and one degree of longitude
    """

    def __init__(
        self, bin_file_location: Optional[str] = None, in_memory: bool = False
    ):
        super().__init__(bin_file_location, in_memory)

    def timezone_at(self, *, lng: float, lat: float) -> Optional[str]:
        """instantly returns the name of the most common zone within the corresponding shortcut

        Note: 'most common' in this context means that the boundary polygons with the most coordinates in sum
            occurring in the corresponding shortcut belong to this zone.

        :param lng: longitude of the point in degree (-180.0 to 180.0)
        :param lat: latitude in degree (90.0 to -90.0)
        :return: the timezone name of the most common zone or None if there are no timezone polygons in this shortcut
        """
        lng, lat = utils.validate_coordinates(lng, lat)
        # Inline fast-path to minimize helper overhead
        hex_id = h3.latlng_to_cell(lat, lng, SHORTCUT_H3_RES)

        shortcut_value = self.shortcut_mapping.get(hex_id)
        if shortcut_value is None:
            return None
        elif isinstance(shortcut_value, int):
            # Zone ID - unique zone case
            return self.zone_name_from_id(shortcut_value)
        else:
            # Polygon array - get the last polygon (most common zone)
            if len(shortcut_value) == 0:
                return None
            poly_of_biggest_zone = shortcut_value[-1]
            # poly_of_biggest_zone is a numpy scalar from array indexing, but mypy sees it as ndarray
            # This is safe: array element access returns a numpy integer scalar compatible with IntegerLike
            most_common_id = self.zone_id_of(poly_of_biggest_zone)  # type: ignore[arg-type]
            return self.zone_name_from_id(most_common_id)


class TimezoneFinder(AbstractTimezoneFinder):
    """Class for quickly finding the timezone of a point on earth offline.

    Because of indexing ("shortcuts"), not all timezone polygons have to be tested during a query.

    Opens the required timezone polygon data in binary files to enable fast access.
    For a detailed documentation of data management please refer to the code documentation of
    `file_converter.py <https://github.com/jannikmi/timezonefinder/blob/master/scripts/file_converter.py>`__

    :ivar binary_data_attributes: the names of all attributes which store the opened binary data files

    :param bin_file_location: path to the binary data files to use, None if native package data should be used
    :param in_memory: Whether to completely read and keep the coordinate data in memory as numpy arrays.
    """

    # __slots__ declared in parents are available in child classes. However, child subclasses will get a __dict__
    # and __weakref__ unless they also define __slots__ (which should only contain names of any additional slots).
    __slots__ = [
        "hole_registry",
        "_boundaries_file",
        "_holes_file",
    ]

    def __init__(
        self, bin_file_location: Optional[str] = None, in_memory: bool = False
    ):
        super().__init__(bin_file_location, in_memory)
        self.holes_dir = utils.get_holes_dir(self.data_location)
        self.boundaries_dir = utils.get_boundaries_dir(self.data_location)
        self.boundaries = PolygonArray(
            data_location=self.boundaries_dir, in_memory=in_memory
        )
        self.holes = PolygonArray(data_location=self.holes_dir, in_memory=in_memory)

        # stores for which polygons (how many) holes exits and the id of the first of those holes
        # since there are very few entries it is feasible to keep them in the memory
        self.hole_registry = self._load_hole_registry()

    def __del__(self) -> None:
        """Clean up resources when the object is destroyed."""
        del self.boundaries
        del self.holes
        del self.hole_registry

    def _load_hole_registry(self) -> Dict[int, Tuple[int, int]]:
        """
        Load and convert the hole registry from JSON file, converting keys to int.
        """
        path = utils.get_hole_registry_path(self.data_location)
        with open(path, encoding="utf-8") as json_file:
            hole_registry_tmp = json.loads(json_file.read())
        # convert the json string keys to int
        return {int(k): v for k, v in hole_registry_tmp.items()}

    @property
    def nr_of_polygons(self) -> int:
        return len(self.boundaries)

    @property
    def nr_of_holes(self) -> int:
        return len(self.holes)

    def coords_of(self, boundary_id: IntegerLike = 0) -> np.ndarray:
        """
        Get the coordinates of a boundary polygon from the FlatBuffers collection.

        :param boundary_id: The index of the polygon.
        :return: Array of coordinates.
        """
        return self.boundaries.coords_of(boundary_id)

    def _iter_hole_ids_of(self, boundary_id: IntegerLike) -> Iterable[int]:
        """
        Yield the hole IDs for a given boundary polygon id.

        :param boundary_id: id of the boundary polygon
        :yield: Hole IDs
        """
        try:
            amount_of_holes, first_hole_id = self.hole_registry[int(boundary_id)]
        except KeyError:
            return
        for i in range(amount_of_holes):
            yield first_hole_id + i

    def _holes_of_poly(self, boundary_id: IntegerLike) -> Iterable[np.ndarray]:
        """
        Get the hole coordinates of a boundary polygon from the FlatBuffers collection.

        :param boundary_id: id of the boundary polygon
        :yield: Generator of hole coordinates
        """
        for hole_id in self._iter_hole_ids_of(boundary_id):
            yield self.holes.coords_of(hole_id)

    def get_polygon(
        self, boundary_id: IntegerLike, coords_as_pairs: bool = False
    ) -> List[Union[CoordPairs, CoordLists]]:
        """
        Get the polygon coordinates of a given boundary polygon including its holes.

        :param boundary_id:  ID of the boundary polygon
        :param coords_as_pairs: If True, returns coordinates as pairs (lng, lat).
            If False, returns coordinates as separate lists of longitudes and latitudes.
        :return: List of polygon coordinates
        """
        list_of_converted_polygons = []
        if coords_as_pairs:
            conversion_method = utils.convert2coord_pairs
        else:
            conversion_method = utils.convert2coords
        list_of_converted_polygons.append(
            conversion_method(self.coords_of(boundary_id=boundary_id))
        )

        for hole in self._holes_of_poly(boundary_id):
            list_of_converted_polygons.append(conversion_method(hole))

        return list_of_converted_polygons

    def get_geometry(
        self,
        tz_name: Optional[str] = "",
        tz_id: Optional[int] = 0,
        use_id: bool = False,
        coords_as_pairs: bool = False,
    ) -> List[List[Union[CoordPairs, CoordLists]]]:
        """retrieves the geometry of a timezone: multiple boundary polygons with holes

        :param tz_name: one of the names in ``timezone_names.json`` or ``self.timezone_names``
        :param tz_id: the id of the timezone (=index in ``self.timezone_names``)
        :param use_id: if ``True`` uses ``tz_id`` instead of ``tz_name``
        :param coords_as_pairs: determines the structure of the polygon representation
        :return: a data structure representing the multipolygon of this timezone
            output format: ``[ [polygon1, hole1, hole2...], [polygon2, ...], ...]``
            and each polygon and hole is itself formatted like: ``([longitudes], [latitudes])``
            or ``[(lng1,lat1), (lng2,lat2),...]`` if ``coords_as_pairs=True``.
        """

        if use_id:
            if not isinstance(tz_id, int):
                raise TypeError("the zone id must be given as int.")
            if tz_id < 0 or tz_id >= self.nr_of_zones:
                raise ValueError(
                    f"the given zone id {tz_id} is invalid (value range: 0 - {self.nr_of_zones - 1}."
                )
        else:
            if tz_name is None:
                raise ValueError("no timezone name given.")
            try:
                tz_id = self.timezone_names.index(tz_name)
            except ValueError:
                raise ValueError("The timezone '", tz_name, "' does not exist.")
        if tz_id is None:
            raise ValueError("no timezone id given.")

        return [
            self.get_polygon(boundary_id, coords_as_pairs)
            for boundary_id in self._iter_boundary_ids_of_zone(tz_id)
        ]

    def inside_of_polygon(self, boundary_id: IntegerLike, x: int, y: int) -> bool:
        """
        Check if a point is inside a boundary polygon.

        :param boundary_id: boundary polygon ID
        :param x: X-coordinate of the point
        :param y: Y-coordinate of the point
        :return: True if the point lies inside the boundary polygon, False if outside or in a hole.
        """
        # avoid running the expensive PIP algorithm at any cost
        # -> check bboxes first
        if self.boundaries.outside_bbox(boundary_id, x, y):
            return False

        # NOTE: holes are much smaller (fewer points) -> less expensive to check
        # -> check holes before the boundary
        hole_id_iter = self._iter_hole_ids_of(boundary_id)
        if self.holes.in_any_polygon(hole_id_iter, x, y):
            # the point is within one of the holes
            # it is excluded fromn this boundary polygon
            return False

        return self.boundaries.pip(boundary_id, x, y)

    def timezone_at(self, *, lng: float, lat: float) -> Optional[str]:
        """
        Find the timezone for a given point using hybrid shortcuts, considering both land and ocean timezones.

        Uses precomputed hybrid shortcuts to reduce the number of polygons checked. Returns the timezone name
        of the matched polygon, which may be an ocean timezone ("Etc/GMT+-XX") if applicable.

        Since ocean timezones span the whole globe, some timezone will always be matched!
        `None` can only be returned when using custom timezone data without such ocean timezones.

        :param lng: longitude of the point in degrees (-180.0 to 180.0)
        :param lat: latitude of the point in degrees (90.0 to -90.0)
        :return: the timezone name of the matched polygon, or None if no match is found.
        """
        # NOTE: performance critical code. avoid helper function call overhead as much as possible
        lng, lat = utils.validate_coordinates(lng, lat)
        hex_id = h3.latlng_to_cell(lat, lng, SHORTCUT_H3_RES)

        # Get shortcut value (hybrid optimization)
        shortcut_value = self.shortcut_mapping.get(hex_id)
        if shortcut_value is None:
            # NOTE: hypothetical case, with ocean data every shortcut maps to at least one boundary polygon
            return None

        if isinstance(shortcut_value, int):
            # Direct zone ID - optimal case for performance
            return self.zone_name_from_id(shortcut_value)

        # Polygon array case - need to check polygons
        possible_boundaries = shortcut_value
        nr_possible_polygons = len(possible_boundaries)
        if nr_possible_polygons == 0:
            return None
        # NOTE: the length 1 case can never occur here, since this is covered by the unique zone shortcut

        # create a list of all the timezone ids of all possible boundary polygons
        zone_ids = self.zone_ids_of(possible_boundaries)

        last_zone_change_idx = utils.get_last_change_idx(zone_ids)
        # NOTE: the case last_zone_change_idx == 0 is covered by the unique zone shortcut

        # ATTENTION: the polygons are stored converted to 32-bit ints,
        # convert the query coordinates in the same fashion in order to make the data formats match
        # x = longitude  y = latitude  both converted to 8byte int
        x = utils.coord2int(lng)
        y = utils.coord2int(lat)

        # check until the point is included in one of the possible boundary polygons
        for i, boundary_id in enumerate(possible_boundaries):
            if i >= last_zone_change_idx:
                # avoid expensive PIP checks when no other zone can be matched anymore
                break

            if self.inside_of_polygon(boundary_id, x, y):
                zone_id = zone_ids[i]
                return self.zone_name_from_id(int(zone_id))

        # since it is the last possible option,
        # the polygons of the last possible zone don't actually have to be checked
        # -> instantly return the last zone
        zone_id = zone_ids[-1]
        return self.zone_name_from_id(int(zone_id))

    def certain_timezone_at(self, *, lng: float, lat: float) -> Optional[str]:
        """checks in which timezone polygon the point is certainly included in using hybrid shortcuts

        .. note:: this is only meaningful when you have compiled your own timezone data
            where there are areas without timezone polygon coverage.
            Otherwise, some timezone will always be matched and the functionality is equal to using `.timezone_at()`
            -> useless to actually test all polygons.

        .. note:: using this function is less performant than `.timezone_at()`

        :param lng: longitude of the point in degree
        :param lat: latitude of the point in degree
        :return: the timezone name of the polygon the point is included in or `None`
        """
        lng, lat = utils.validate_coordinates(lng, lat)
        hex_id = h3.latlng_to_cell(lat, lng, SHORTCUT_H3_RES)

        # Get shortcut value (hybrid optimization)
        shortcut_value = self.shortcut_mapping.get(hex_id)
        if shortcut_value is None:
            return None

        # ATTENTION: the polygons are stored converted to 32-bit ints,
        # convert the query coordinates in the same fashion in order to make the data formats match
        # x = longitude  y = latitude  both converted to 8byte int
        x = utils.coord2int(lng)
        y = utils.coord2int(lat)

        # check if the query point is found to be truly included in one of the possible boundary polygons
        if isinstance(shortcut_value, int):
            # For zone IDs, iterate directly over boundary polygons for that zone
            # Most polygons will be quickly ruled out by bbox check
            boundary_ids = self._iter_boundary_ids_of_zone(shortcut_value)
        else:
            # Polygon array case - iterate directly over the array
            boundary_ids = shortcut_value

        for boundary_id in boundary_ids:
            if self.inside_of_polygon(boundary_id, x, y):
                zone_id = self.zone_id_of(boundary_id)
                return self.zone_name_from_id(zone_id)

        # none of the boundary polygon candidates truly matched
        return None
