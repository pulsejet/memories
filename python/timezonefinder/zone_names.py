from pathlib import Path
from typing import List

from timezonefinder.configs import DEFAULT_DATA_DIR


def get_zone_names_path(output_path: Path = DEFAULT_DATA_DIR) -> Path:
    """Get the path to the timezone names text file."""
    return output_path / "timezone_names.txt"


def write_zone_names(
    zone_names: List[str], output_path: Path = DEFAULT_DATA_DIR
) -> None:
    """
    Write timezone names to a text file.

    Args:
        zone_names: List of timezone names.
        output_path: Directory where the output file will be written.
    """
    path = get_zone_names_path(output_path)
    with open(path, "w", encoding="utf-8") as f:
        f.write("\n".join(zone_names))
        f.write("\n")  # write a newline at the end of the file


def read_zone_names(path: Path) -> List[str]:
    """
    Read timezone names from a text file.

    Args:
        path: Path to the timezone names text file.
              If None, the default path will be used.

    Returns:
        List of timezone names.
    """
    file_path = get_zone_names_path(path)
    with open(file_path, encoding="utf-8") as f:
        return [line.strip() for line in f if line.strip()]
