"""MkDocs hook: inject the current year into the copyright string at build time."""

from datetime import datetime, timezone


def on_config(config):
    """Replace {year} placeholder in the copyright field with the current year."""
    if config.get("copyright"):
        config["copyright"] = config["copyright"].replace(
            "{year}", str(datetime.now(timezone.utc).year)
        )
    return config
