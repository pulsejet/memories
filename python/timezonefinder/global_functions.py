"""
This module provides global functions that use a singleton instance of TimezoneFinder.

Note on thread safety: These global functions are not thread-safe. If you need to use
TimezoneFinder in a multi-threaded environment, create separate TimezoneFinder instances
for each thread.
"""

from typing import List, Optional, Union

from timezonefinder.timezonefinder import TimezoneFinder
from timezonefinder.configs import CoordPairs, CoordLists

# Use a global variable to store the singleton instance
TF_INSTANCE: TimezoneFinder


def _get_tf_instance() -> TimezoneFinder:
    """Get or create the global TimezoneFinder instance

    Lazy initialization: delayed memory allocation until actually needed
    required because, the package might be used with a user defined instance
    and duplicate initialisation overhead must be avoided!
    """
    global TF_INSTANCE
    try:
        return TF_INSTANCE
    except NameError:
        # If TF_INSTANCE is not defined, create it
        TF_INSTANCE = TimezoneFinder()
    return TF_INSTANCE


def timezone_at(*, lng: float, lat: float) -> Optional[str]:
    """
    Looks up in which timezone the given coordinate is included in.
    Uses the global TimezoneFinder instance.

    Note: This function is not thread-safe. For multi-threaded environments,
    create separate TimezoneFinder instances.

    :param lng: longitude of the point in degree (-180.0 to 180.0)
    :param lat: latitude in degree (90.0 to -90.0)
    :return: the timezone name of a matching polygon or None
    """
    return _get_tf_instance().timezone_at(lng=lng, lat=lat)


def timezone_at_land(*, lng: float, lat: float) -> Optional[str]:
    """
    Computes in which land timezone a point is included in.
    Uses the global TimezoneFinder instance.

    Note: This function is not thread-safe. For multi-threaded environments,
    create separate TimezoneFinder instances.

    :param lng: longitude of the point in degree (-180.0 to 180.0)
    :param lat: latitude in degree (90.0 to -90.0)
    :return: the timezone name of a matching polygon or
        ``None`` when an ocean timezone ("Etc/GMT+-XX") has been matched.
    """
    return _get_tf_instance().timezone_at_land(lng=lng, lat=lat)


def unique_timezone_at(*, lng: float, lat: float) -> Optional[str]:
    """
    Returns the name of a unique zone within the corresponding shortcut.
    Uses the global TimezoneFinder instance.

    Note: This function is not thread-safe. For multi-threaded environments,
    create separate TimezoneFinder instances.

    :param lng: longitude of the point in degree (-180.0 to 180.0)
    :param lat: latitude in degree (90.0 to -90.0)
    :return: the timezone name of the unique zone or ``None`` if there are no or multiple zones in this shortcut
    """
    return _get_tf_instance().unique_timezone_at(lng=lng, lat=lat)


def certain_timezone_at(*, lng: float, lat: float) -> Optional[str]:
    """
    Checks in which timezone polygon the point is certainly included in.
    Uses the global TimezoneFinder instance.

    Note: This function is not thread-safe. For multi-threaded environments,
    create separate TimezoneFinder instances.

    .. note:: this is only meaningful when you have compiled your own timezone data
        where there are areas without timezone polygon coverage.
        Otherwise, some timezone will always be matched and the functionality is equal to using `.timezone_at()`
        -> useless to actually test all polygons.

    .. note:: using this function is less performant than `.timezone_at()`

    :param lng: longitude of the point in degree
    :param lat: latitude in degree
    :return: the timezone name of the polygon the point is included in or `None`
    """
    return _get_tf_instance().certain_timezone_at(lng=lng, lat=lat)


def get_geometry(
    tz_name: Optional[str] = "",
    tz_id: Optional[int] = 0,
    use_id: bool = False,
    coords_as_pairs: bool = False,
) -> List[List[Union[CoordPairs, CoordLists]]]:
    """
    Retrieves the geometry of a timezone polygon.
    Uses the global TimezoneFinder instance.

    Note: This function is not thread-safe. For multi-threaded environments,
    create separate TimezoneFinder instances.

    :param tz_name: one of the names in ``timezone_names.json`` or ``self.timezone_names``
    :param tz_id: the id of the timezone (=index in ``self.timezone_names``)
    :param use_id: if ``True`` uses ``tz_id`` instead of ``tz_name``
    :param coords_as_pairs: determines the structure of the polygon representation
    :return: a data structure representing the multipolygon of this timezone
        output format: ``[ [polygon1, hole1, hole2...], [polygon2, ...], ...]``
        and each polygon and hole is itself formatted like: ``([longitudes], [latitudes])``
        or ``[(lng1,lat1), (lng2,lat2),...]`` if ``coords_as_pairs=True``.
    """
    return _get_tf_instance().get_geometry(
        tz_name=tz_name, tz_id=tz_id, use_id=use_id, coords_as_pairs=coords_as_pairs
    )
