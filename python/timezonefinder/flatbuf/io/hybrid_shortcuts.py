"""Utilities for working with optimized hybrid shortcut FlatBuffer data."""

from pathlib import Path
from typing import Any, Callable, Dict, List, Union
from dataclasses import dataclass

import flatbuffers
import numpy as np

from timezonefinder.configs import DEFAULT_DATA_DIR

# Static imports for uint8 schema
from timezonefinder.flatbuf.generated.shortcuts_uint8.HybridShortcutCollection import (
    HybridShortcutCollection as HybridShortcutCollectionUint8,
    HybridShortcutCollectionAddEntries as HybridShortcutCollectionAddEntriesUint8,
    HybridShortcutCollectionEnd as HybridShortcutCollectionEndUint8,
    HybridShortcutCollectionStart as HybridShortcutCollectionStartUint8,
    HybridShortcutCollectionStartEntriesVector as HybridShortcutCollectionStartEntriesVectorUint8,
)
from timezonefinder.flatbuf.generated.shortcuts_uint8.HybridShortcutEntry import (
    HybridShortcutEntryAddHexId as HybridShortcutEntryAddHexIdUint8,
    HybridShortcutEntryAddValue as HybridShortcutEntryAddValueUint8,
    HybridShortcutEntryAddValueType as HybridShortcutEntryAddValueTypeUint8,
    HybridShortcutEntryEnd as HybridShortcutEntryEndUint8,
    HybridShortcutEntryStart as HybridShortcutEntryStartUint8,
)
from timezonefinder.flatbuf.generated.shortcuts_uint8.UniqueZone import (
    UniqueZone as UniqueZoneUint8,
    UniqueZoneAddZoneId as UniqueZoneAddZoneIdUint8,
    UniqueZoneEnd as UniqueZoneEndUint8,
    UniqueZoneStart as UniqueZoneStartUint8,
)
from timezonefinder.flatbuf.generated.shortcuts_uint8.PolygonList import (
    PolygonList as PolygonListUint8,
    PolygonListAddPolyIds as PolygonListAddPolyIdsUint8,
    PolygonListEnd as PolygonListEndUint8,
    PolygonListStart as PolygonListStartUint8,
    PolygonListStartPolyIdsVector as PolygonListStartPolyIdsVectorUint8,
)
from timezonefinder.flatbuf.generated.shortcuts_uint8.ShortcutValue import (
    ShortcutValue as ShortcutValueUint8,
)

# Static imports for uint16 schema
from timezonefinder.flatbuf.generated.shortcuts_uint16.HybridShortcutCollection import (
    HybridShortcutCollection as HybridShortcutCollectionUint16,
    HybridShortcutCollectionAddEntries as HybridShortcutCollectionAddEntriesUint16,
    HybridShortcutCollectionEnd as HybridShortcutCollectionEndUint16,
    HybridShortcutCollectionStart as HybridShortcutCollectionStartUint16,
    HybridShortcutCollectionStartEntriesVector as HybridShortcutCollectionStartEntriesVectorUint16,
)
from timezonefinder.flatbuf.generated.shortcuts_uint16.HybridShortcutEntry import (
    HybridShortcutEntryAddHexId as HybridShortcutEntryAddHexIdUint16,
    HybridShortcutEntryAddValue as HybridShortcutEntryAddValueUint16,
    HybridShortcutEntryAddValueType as HybridShortcutEntryAddValueTypeUint16,
    HybridShortcutEntryEnd as HybridShortcutEntryEndUint16,
    HybridShortcutEntryStart as HybridShortcutEntryStartUint16,
)
from timezonefinder.flatbuf.generated.shortcuts_uint16.UniqueZone import (
    UniqueZone as UniqueZoneUint16,
    UniqueZoneAddZoneId as UniqueZoneAddZoneIdUint16,
    UniqueZoneEnd as UniqueZoneEndUint16,
    UniqueZoneStart as UniqueZoneStartUint16,
)
from timezonefinder.flatbuf.generated.shortcuts_uint16.PolygonList import (
    PolygonList as PolygonListUint16,
    PolygonListAddPolyIds as PolygonListAddPolyIdsUint16,
    PolygonListEnd as PolygonListEndUint16,
    PolygonListStart as PolygonListStartUint16,
    PolygonListStartPolyIdsVector as PolygonListStartPolyIdsVectorUint16,
)
from timezonefinder.flatbuf.generated.shortcuts_uint16.ShortcutValue import (
    ShortcutValue as ShortcutValueUint16,
)


@dataclass
class SchemaImports:
    """Container for schema-specific imports to eliminate magic strings."""

    # Collection functions
    collection_start: Callable[..., Any]
    collection_add_entries: Callable[..., Any]
    collection_end: Callable[..., Any]
    collection_start_entries_vector: Callable[..., Any]

    # Entry functions
    entry_start: Callable[..., Any]
    entry_add_hex_id: Callable[..., Any]
    entry_add_value_type: Callable[..., Any]
    entry_add_value: Callable[..., Any]
    entry_end: Callable[..., Any]

    # UniqueZone functions
    unique_zone_start: Callable[..., Any]
    unique_zone_add_zone_id: Callable[..., Any]
    unique_zone_end: Callable[..., Any]

    # PolygonList functions
    polygon_list_start: Callable[..., Any]
    polygon_list_add_poly_ids: Callable[..., Any]
    polygon_list_end: Callable[..., Any]
    polygon_list_start_poly_ids_vector: Callable[..., Any]

    # ShortcutValue enum
    shortcut_value: Any

    # Validation parameters
    max_zone_id: int
    dtype_name: str


@dataclass
class ReadSchemaImports:
    """Container for read-specific schema imports."""

    collection: Any
    unique_zone: Any
    polygon_list: Any
    shortcut_value: Any


def get_hybrid_shortcut_file_path(
    zone_id_dtype: np.dtype, output_path: Path = DEFAULT_DATA_DIR
) -> Path:
    """Return the path to the appropriate hybrid shortcut FlatBuffer binary file."""
    if zone_id_dtype.itemsize == 1:
        return output_path / "hybrid_shortcuts_uint8.fbs"
    elif zone_id_dtype.itemsize == 2:
        return output_path / "hybrid_shortcuts_uint16.fbs"
    else:
        raise ValueError(
            f"Unsupported zone_id_dtype: {zone_id_dtype}. Use uint8 or uint16."
        )


def _validate_zone_id_dtype(zone_id_dtype: np.dtype) -> np.dtype:
    """Validate and normalize zone ID dtype."""
    dtype = np.dtype(zone_id_dtype)
    if dtype.kind != "u":
        raise ValueError(f"Zone id dtype must be unsigned integer, got {dtype}")
    if dtype.itemsize not in (1, 2):
        raise ValueError(
            f"Zone id dtype must be 1 or 2 bytes, got {dtype.itemsize} bytes"
        )
    return dtype.newbyteorder("<")


def write_hybrid_shortcuts_flatbuffers(
    hybrid_mapping: Dict[int, Union[int, List[int]]],
    zone_id_dtype: np.dtype,
    output_file: Path,
) -> None:
    """
    Write hybrid shortcut mapping to the appropriate optimized FlatBuffer binary file.

    Args:
        hybrid_mapping: Dictionary mapping H3 hexagon IDs to either:
                       - int: unique zone ID (when all polygons share same zone)
                       - List[int]: list of polygon IDs (when multiple zones)
        zone_id_dtype: numpy dtype for zone IDs (uint8 or uint16)
        output_file: Path to save the FlatBuffer file
    """
    print(f"Writing {len(hybrid_mapping)} optimized hybrid shortcuts to {output_file}")

    dtype = _validate_zone_id_dtype(zone_id_dtype)
    _write_hybrid_shortcuts_generic(hybrid_mapping, dtype, output_file)


def _write_hybrid_shortcuts_generic(
    hybrid_mapping: Dict[int, Union[int, List[int]]],
    zone_id_dtype: np.dtype,
    output_file: Path,
) -> None:
    """Write hybrid shortcuts using the appropriate schema based on dtype."""
    if zone_id_dtype.itemsize == 1:
        # uint8 schema imports
        schema = SchemaImports(
            collection_start=HybridShortcutCollectionStartUint8,
            collection_add_entries=HybridShortcutCollectionAddEntriesUint8,
            collection_end=HybridShortcutCollectionEndUint8,
            collection_start_entries_vector=HybridShortcutCollectionStartEntriesVectorUint8,
            entry_start=HybridShortcutEntryStartUint8,
            entry_add_hex_id=HybridShortcutEntryAddHexIdUint8,
            entry_add_value_type=HybridShortcutEntryAddValueTypeUint8,
            entry_add_value=HybridShortcutEntryAddValueUint8,
            entry_end=HybridShortcutEntryEndUint8,
            unique_zone_start=UniqueZoneStartUint8,
            unique_zone_add_zone_id=UniqueZoneAddZoneIdUint8,
            unique_zone_end=UniqueZoneEndUint8,
            polygon_list_start=PolygonListStartUint8,
            polygon_list_add_poly_ids=PolygonListAddPolyIdsUint8,
            polygon_list_end=PolygonListEndUint8,
            polygon_list_start_poly_ids_vector=PolygonListStartPolyIdsVectorUint8,
            shortcut_value=ShortcutValueUint8,
            max_zone_id=255,
            dtype_name="uint8",
        )
    else:
        # uint16 schema imports
        schema = SchemaImports(
            collection_start=HybridShortcutCollectionStartUint16,
            collection_add_entries=HybridShortcutCollectionAddEntriesUint16,
            collection_end=HybridShortcutCollectionEndUint16,
            collection_start_entries_vector=HybridShortcutCollectionStartEntriesVectorUint16,
            entry_start=HybridShortcutEntryStartUint16,
            entry_add_hex_id=HybridShortcutEntryAddHexIdUint16,
            entry_add_value_type=HybridShortcutEntryAddValueTypeUint16,
            entry_add_value=HybridShortcutEntryAddValueUint16,
            entry_end=HybridShortcutEntryEndUint16,
            unique_zone_start=UniqueZoneStartUint16,
            unique_zone_add_zone_id=UniqueZoneAddZoneIdUint16,
            unique_zone_end=UniqueZoneEndUint16,
            polygon_list_start=PolygonListStartUint16,
            polygon_list_add_poly_ids=PolygonListAddPolyIdsUint16,
            polygon_list_end=PolygonListEndUint16,
            polygon_list_start_poly_ids_vector=PolygonListStartPolyIdsVectorUint16,
            shortcut_value=ShortcutValueUint16,
            max_zone_id=65535,
            dtype_name="uint16",
        )

    _write_hybrid_shortcuts_with_schema(hybrid_mapping, output_file, schema)


def _write_hybrid_shortcuts_with_schema(
    hybrid_mapping: Dict[int, Union[int, List[int]]],
    output_file: Path,
    schema: SchemaImports,
) -> None:
    """Write hybrid shortcuts using the provided schema imports."""
    builder = flatbuffers.Builder(0)
    entry_offsets = []

    # Validate zone IDs fit in dtype
    for value in hybrid_mapping.values():
        if isinstance(value, int) and value > schema.max_zone_id:
            raise ValueError(
                f"Zone ID {value} exceeds {schema.dtype_name} maximum ({schema.max_zone_id})"
            )

    for hex_id, value in hybrid_mapping.items():
        if isinstance(value, int):
            # Create UniqueZone with direct storage
            schema.unique_zone_start(builder)
            schema.unique_zone_add_zone_id(builder, value)
            unique_zone_offset = schema.unique_zone_end(builder)

            # Create entry with UniqueZone
            schema.entry_start(builder)
            schema.entry_add_hex_id(builder, hex_id)
            schema.entry_add_value_type(builder, schema.shortcut_value.UniqueZone)
            schema.entry_add_value(builder, unique_zone_offset)
            entry_offset = schema.entry_end(builder)

        else:
            # Create PolygonList
            poly_ids = list(value)
            schema.polygon_list_start_poly_ids_vector(builder, len(poly_ids))
            for i in range(len(poly_ids) - 1, -1, -1):
                builder.PrependUint16(poly_ids[i])
            poly_ids_vector = builder.EndVector()

            schema.polygon_list_start(builder)
            schema.polygon_list_add_poly_ids(builder, poly_ids_vector)
            polygon_list_offset = schema.polygon_list_end(builder)

            # Create entry with PolygonList
            schema.entry_start(builder)
            schema.entry_add_hex_id(builder, hex_id)
            schema.entry_add_value_type(builder, schema.shortcut_value.PolygonList)
            schema.entry_add_value(builder, polygon_list_offset)
            entry_offset = schema.entry_end(builder)

        entry_offsets.append(entry_offset)

    # Create entries vector
    schema.collection_start_entries_vector(builder, len(entry_offsets))
    for offset in reversed(entry_offsets):
        builder.PrependUOffsetTRelative(offset)
    entries_vector = builder.EndVector()

    # Create HybridShortcutCollection
    schema.collection_start(builder)
    schema.collection_add_entries(builder, entries_vector)
    collection = schema.collection_end(builder)

    builder.Finish(collection)

    # Write to file
    with open(output_file, "wb") as f:
        f.write(builder.Output())


def read_hybrid_shortcuts_binary(
    file_path: Path,
) -> Dict[int, Union[int, np.ndarray]]:
    """
    Read hybrid shortcut mapping from an optimized FlatBuffer binary file.

    Auto-detects whether the file uses uint8 or uint16 schema based on filename.

    Args:
        file_path: Path to the hybrid shortcut FlatBuffer file

    Returns:
        Dictionary mapping H3 hexagon IDs to either:
        - int: unique zone ID (when all polygons share same zone)
        - np.ndarray: array of polygon IDs (when multiple zones)
    """
    # Determine schema type from filename and select appropriate imports
    if "uint8" in file_path.name:
        schema = ReadSchemaImports(
            collection=HybridShortcutCollectionUint8,
            unique_zone=UniqueZoneUint8,
            polygon_list=PolygonListUint8,
            shortcut_value=ShortcutValueUint8,
        )
    elif "uint16" in file_path.name:
        schema = ReadSchemaImports(
            collection=HybridShortcutCollectionUint16,
            unique_zone=UniqueZoneUint16,
            polygon_list=PolygonListUint16,
            shortcut_value=ShortcutValueUint16,
        )
    else:
        raise ValueError(
            f"Cannot determine schema from filename: {file_path.name}. "
            "Filename must include 'uint8' or 'uint16'."
        )

    return _read_hybrid_shortcuts_with_schema(file_path, schema)


def _read_hybrid_shortcuts_with_schema(
    file_path: Path, schema: ReadSchemaImports
) -> Dict[int, Union[int, np.ndarray]]:
    """Read hybrid shortcuts using the provided schema imports."""
    with open(file_path, "rb") as f:
        buf = f.read()

    # mypy: GetRootAs is a class method on FlatBuffers classes
    collection = schema.collection.GetRootAs(buf, 0)  # type: ignore

    hybrid_mapping: Dict[int, Union[int, np.ndarray]] = {}
    for i in range(collection.EntriesLength()):
        entry = collection.Entries(i)
        hex_id = entry.HexId()

        # Determine value type and extract data
        value_type = entry.ValueType()
        value = entry.Value()

        if value_type == schema.shortcut_value.UniqueZone:
            unique_zone = schema.unique_zone()  # type: ignore
            unique_zone.Init(value.Bytes, value.Pos)
            zone_id = unique_zone.ZoneId()  # Direct zone ID, no lookup needed
            hybrid_mapping[hex_id] = int(zone_id)

        elif value_type == schema.shortcut_value.PolygonList:
            polygon_list = schema.polygon_list()  # type: ignore
            polygon_list.Init(value.Bytes, value.Pos)
            poly_ids = polygon_list.PolyIdsAsNumpy()
            hybrid_mapping[hex_id] = poly_ids

        else:
            raise ValueError(f"Unknown ShortcutValue type: {value_type}")

    return hybrid_mapping
