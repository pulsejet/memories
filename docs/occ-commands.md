---
description: Documentation for Memories OCC commands
---

# OCC commands

Memories provides several OCC commands for administration. For usage of the `occ` command line, refer [here](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/occ_command.html) for more information.

!!! warning "OCCWeb"

    The OCCWeb app is deprecated, and will not work with Memories. You must use the `occ` command line.

## `occ memories:index`

This is the basic command for indexing metadata in files. You don't need to run this in a cron job since this is handled as a background job automatically. You can use the index command to speed up indexing if you just installed the app or if you have a lot of files that are not indexed yet.

!!! tip "Indexing in parallel"

    You can run multiple processes of indexing in parallel, e.g. `for i in {1..4}; do (occ memories:index &); done`. This will speed up indexing significantly.

```
Usage:
  memories:index [options]

Options:
  -u, --user=USER       Index only the specified user
  -g, --group=GROUP     Index only specified group
      --folder=FOLDER   Index only the specified folder (relative to the user's root)
  -f, --force           Force refresh of existing index entries
      --clear           Clear all existing index entries
      --retry           Retry indexing of failed files
      --skip-cleanup    Skip cleanup step (removing index entries with missing files)
```

!!! info "Re-indexing"

    Running the command again will NOT reindex everything. It will only index new files and update the index for changed files.
    If you really want to reindex everything, use the `--force` option or `--clear` to truncate all Memories tables before the index.

## `occ memories:places-setup`

Download and index the planet database. The planet database is the border map of the entire world and is used for reverse geocoding (not for the map). To use reverse geocoding, MySQL or Postgres is required.

```
Usage:
  memories:places-setup [options]

Options:
  -f, --force                Ignore existing setup and re-download planet
  -r, --recalculate          Only recalculate places for existing files
      --transaction-size=10  Reduce this value if your database crashes [default: 10]
```

## `memories:migrate-google-takeout`

Migrate Google Takeout JSON metadata to the files as EXIF data.

```
Usage:
  memories:migrate-google-takeout [options]

Options:
  -o, --override        Override existing EXIF metadata
  -u, --user=USER       Migrate only for the specified user
  -f, --folder=FOLDER   Migrate only for the specified folder
```

!!! warning "Updates to files"

    This command will modify the files in the user's data directory. Make sure you have a backup!
