---
description: Solutions to common problems
---

# Troubleshooting

This page contains solutions to common problems. If you are facing any issues, please refer to this page before opening an issue.

## Performance

!!! tip "Nextcloud AIO"

    If you are using [AIO](https://github.com/nextcloud/all-in-one), these options should be configured by default.

Memories is very fast, but its performance largely depends on how well the Nextcloud instance is tuned.

- Make sure you are running the latest stable version of Nextcloud and Memories.
- Follow the steps in the [server tuning](https://docs.nextcloud.com/server/latest/admin_manual/installation/server_tuning.html) documentation.
- Follow all configuration steps in the [configuration](../config) documentation.

      - Disable video transcoding if your server is not powerful enough.
      - Reduce the maximum size of previews to be generated.

- Make sure you are running HTTPS (very important).
- Enable HTTP/2 or HTTP/3 on your server.
- Enable and configure PHP Opcache and JIT.
- Enable and configure the APCu cache.
- Enable and configure Redis for transactional file locking.
- Enable gzip compression on your HTTP server for static assets (CSS/JS).

## No photos are shown

This means that Memories is unable to find any indexed photos in your Nextcloud instance. Make sure you have followed the [configuration steps](../config). Note that Memories indexes photos in the background, so it may take a while for the photos to show up. Ensure that Nextcloud's cron system is properly configured and running.

## Issues with Docker

Note: Using the community [Nextcloud Docker](https://hub.docker.com/_/nextcloud/) image or [AIO](https://github.com/nextcloud/all-in-one) are the recommended ways of running Memories. If you are using a different image, you may run into issues. Please file any bugs you find on GitHub.

### OCC commands fail

The most common reason for this is a missing interactive TTY. Make sure you run the commands with `-it`:

```bash
docker exec -it my_nc_container php occ memories:index
#           ^^^  <-- this is required
```

If you are using Nextcloud AIO, see [this documentation](https://github.com/nextcloud/all-in-one#how-to-run-occ-commands).

!!! warning "OCCWeb"

    The OCCWeb app is deprecated, and will not work with Memories. You must use the `occ` command line.

### Usage of tmpfs

If you are using `tmpfs` (e.g. for the Recognize app), make sure the temp directory is set to executable. With Docker compose, your `docker-compose.yml` should look like this:

```yaml
app:
    ...
    tmpfs:
    - /tmp:exec
```

`tpmfs` is automatically configured when using Nextcloud AIO v7.0.0.

## Reverse Geocoding (Places)

You need to have a MySQL / MariaDB / Postgres database for reverse geocoding to work. SQLite is not supported.

### Planet DB download fails

If the planet DB download does not complete via the admin interface, you need to use the OCC command line, or increase the connection timeout values for your PHP/HTTP servers.

```bash
occ memories:places-setup
```

### Error: Incorrect string value

If you get this error, it is likely that your database is not using the `utf8mb4` character set. Since the reverse geocoding database contains characters in various languages, it is necessary to use `utf8mb4` to store them. To fix this, you need to convert your database to use `utf8mb4`.

## Reset

If you want to completely reset Memories (e.g. for database trouble), uninstall it from the app store, then run the following SQL on your database to clean up any data.

```sql
DROP TABLE IF EXISTS oc_memories;
DROP TABLE IF EXISTS oc_memories_livephoto;
DROP TABLE IF EXISTS oc_memories_mapclusters;
DROP TABLE IF EXISTS oc_memories_places;
DROP TABLE IF EXISTS oc_memories_planet;
DROP TABLE IF EXISTS memories_planet_geometry;
DROP INDEX IF EXISTS memories_parent_mimetype ON oc_filecache; /* MySQL */
DELETE FROM oc_migrations WHERE app='memories';
```

On Postgres, the syntax for dropping the index is:

```sql
DROP INDEX IF EXISTS memories_parent_mimetype;
```
