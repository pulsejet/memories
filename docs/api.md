---
description: REST API reference for the Memories Nextcloud app
---

# REST API

Memories exposes a JSON HTTP API under `/apps/memories/api`. Controllers live in `lib/Controller/` and routes are registered in `appinfo/routes.php`. The web UI builds URLs with [`src/services/API.ts`](https://github.com/pulsejet/memories/blob/master/src/services/API.ts).

!!! note "Stability"

    This API is primarily consumed by the Memories frontend and native clients. There is no published OpenAPI / OCS schema. Treat paths and query parameters as the source of truth in `appinfo/routes.php`, and expect additive changes between releases.

## Base URL and discovery

All paths below are relative to the Nextcloud origin, for example `https://cloud.example.com/apps/memories/api/...`.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/describe` | Public | App version, absolute base URL, Client Login Flow V2 URL, optional `uid`. Add `?manifest=1` for JS/CSS manifests. Response allows CORS `*`. |

Example:

```bash
curl -sS "https://cloud.example.com/apps/memories/api/describe"
```

```json
{
  "version": "7.x.x",
  "baseUrl": "https://cloud.example.com/apps/memories/",
  "loginFlowUrl": "https://cloud.example.com/index.php/login/v2",
  "uid": null
}
```

## Authentication

Most endpoints require a logged-in Nextcloud user (`NoAdminRequired`). Prefer a normal session cookie (browser) or an [app password](https://docs.nextcloud.com/server/latest/user_manual/en/session_management.html) over HTTP Basic.

- **Mutating requests** (POST / PUT / PATCH / DELETE) from a browser session need the Nextcloud `requesttoken` header (same as other AppFramework APIs).
- Endpoints marked **public** also accept folder or album share access via query `token=` (and `albums=` for album shares). The web client appends these automatically on share routes.
- Admin-only routes require a Nextcloud admin account.
- Boolean query flags are typically presence-based: send `1` (or any value) to enable; omit to disable.

Public share page routes (`/s/{token}`, `/a/{token}`) are separate from this JSON API.

## Timeline

`dayid` is the UTC epoch day: `floor(datetaken / 86400)`.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/days` | Public | Day aggregates (`dayid`, `count`). May embed early `detail` photo arrays unless `nopreload=1`. |
| `POST` | `/api/days` | Public | Photos for body `{ "dayIds": [int, ...] }`. |
| `GET` | `/api/days/{id}` | Public | Same as POST; `{id}` may be a comma-separated list of day IDs. |

### Timeline query parameters

| Parameter | Effect |
|-----------|--------|
| `folder` | Restrict to a folder path (relative to user or share root). Without `recursive`, listing is non-recursive. |
| `recursive` | Recursive folder listing when `folder` is set. |
| `archive` | Only archived photos. |
| `hidden` | Include hidden items where applicable. |
| `monthView` | Month-oriented aggregation. |
| `reverse` | Reverse chronological order. |
| `nopreload` | Skip embedding `detail` on `GET /api/days`. |
| `limit` | Cap rows on day detail queries (logged-in only). |
| `fav` | Favorites only (logged-in). |
| `vid` | Videos only (logged-in). |
| `pano` | Panoramas only (logged-in). |
| `mapbounds` | Geographic bounding box filter (logged-in). |
| `albums` | Filter by album id (cluster backend). |
| `tags` | Filter by tag name. |
| `places` | Filter by place id. |
| `recognize` | Filter by Recognize face/person key. |
| `facerecognition` | Filter by Face Recognition person key. |
| `facerect` | Include face rectangles for face filters. |

Logged-out (public share) callers only get cluster-related transforms; `fav` / `vid` / `pano` / `mapbounds` / `limit` are ignored.

Typical photo object fields: `fileid`, `dayid`, `w`, `h`, `basename`, `epoch`, `mimetype`, `size`, `flag`.

```bash
# List days without preloading photo details
curl -u user:app-password \
  "https://cloud.example.com/apps/memories/api/days?nopreload=1"

# Photos for one day
curl -u user:app-password \
  "https://cloud.example.com/apps/memories/api/days/19354"
```

## Folders

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/folders/sub?folder=` | Public | Immediate child folders with preview hints (`fileid`, `name`, `previews`). |

## Clusters

Cluster backends group media under `/api/clusters/{backend}` where `{backend}` is one of:

`albums` · `tags` · `places` · `recognize` · `facerecognition`

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/clusters/{backend}` | User | List clusters. Optional `fileid` scopes to clusters containing that file. |
| `GET` | `/api/clusters/{backend}/preview` | User | Cover / mosaic preview image (`name`, optional `cover`, `cover_etag`). |
| `POST` | `/api/clusters/{backend}/set-cover` | User | Set cover: body/params `name`, `fileid`. |
| `POST` | `/api/clusters/{backend}/download` | User | Create download handle for all files in the cluster (`name`). Returns `{ "handle": "..." }`. |
| `PATCH` | `/api/tags/set/{id}` | User | Add/remove system tags on file `{id}`: `{ "add": [tagId], "remove": [tagId] }`. |

Places listing accepts extras such as `inside`, `mark`, and `covers` (see `PlacesBackend`).

## Map

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/map/init` | User | Initial map camera hint (`pos`). |
| `GET` | `/api/map/clusters?bounds=&zoom=` | User | Geohash clusters for the viewport; each cluster may include a `preview` photo. |

`bounds` and numeric `zoom` are required for `/api/map/clusters`.

## Images

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/image/preview/{id}` | Public | Single preview (`x`, `y`, `a`, `mode`). |
| `POST` | `/api/image/multipreview` | Public | Batch previews; body `files[]` with `reqid`, `fileid`, `x`, `y`, `a`. Binary octet-stream response. |
| `GET` | `/api/image/info/{id}` | Public | Metadata JSON. Query: `basic`, `current` (fresh EXIF), `tags`, `clusters` (comma-separated backends). |
| `PATCH` | `/api/image/set-exif/{id}` | Public | Write allowed EXIF tags from body `raw`. Returns updated info. |
| `POST` | `/api/image/reindex/{id}` | User | Force reindex one file. |
| `GET` | `/api/image/decodable/{id}` | Public | Browser-decodable full image for editing (`hevc`, ETag support). |
| `PUT` | `/api/image/edit/{id}` | Public | Apply edits (`name`, `extension`, `edits`, `quality`, `copy`). |
| `DELETE` | `/api/image/delete/{id}` | Public | Delete file if permitted. |

Allowed EXIF keys for `set-exif` include dates, orientation, title/description/label, camera/lens, copyright, and GPS fields (see `ImageController::setExif`).

## Video

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/video/transcode/{client}/{fileid}/{profile}` | Public | HLS / transcode proxy via go-vod (`codecs` query optional). |
| `GET` | `/api/video/storyboard/{client}/{fileid}/{profile}` | Public | Storyboard assets for seeking UI. |
| `GET` | `/api/video/livephoto/{fileid}` | Public | Motion-photo companion video (or metadata). |

`{client}` is a client-chosen id used to correlate transcoder sessions. Video playback requires [transcoding](hw-transcoding.md) to be configured.

## Download and stream

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `POST` | `/api/download` | Public | Body `{ "files": [fileid, ...] }` → `{ "handle": "..." }`. |
| `GET` | `/api/download/{handle}` | Public | Consume handle (one-shot for GET; HEAD does not invalidate). Single file streams; multiple files zip. |
| `GET` | `/api/stream/{fileid}` | Public | Stream one file. `attachment=1` forces download disposition. |

## Archive, trash, shares

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `PATCH` | `/api/archive/{id}` | User | Toggle archive for file id (moves under `.archive` in the timeline tree). |
| `GET` | `/api/trash/list` | User | Flat list of trashed images/videos in timeline/folder scopes. |
| `GET` | `/api/share/links` | User | Link shares for a node (`id` or `path`). |
| `POST` | `/api/share/node` | User | Create a read-only public link share (`id` or `path`). |
| `POST` | `/api/share/delete` | User | Delete share by share `id`. |

## User config

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/config` | Public | Version, feature flags (albums, recognize, facerecognition, lens, …), and user preferences. |
| `PUT` | `/api/config/{key}` | User | Set one user preference string (`value`). Blocked when Memories is in read-only mode. |

## Lens (optional)

Requires a configured Lens daemon (`lens_enabled` in `/api/config`).

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/lens/search?text=&limit=` | User | Text search over the user's timeline (limit clamped 1–500). |
| `GET` | `/lens/file/{fileid}` | Service | Raw file + `X-Memories-Metadata` for the Lens service account. |
| `POST` | `/lens/scan` | Service | Next catalog batch for daemon reconciliation. |

## Admin

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/api/system-status` | Admin | Index counts, binary checks (exiftool, vips, …), DB warnings, VOD status. |
| `GET` | `/api/system-config` | Admin | All Memories system config keys. |
| `PUT` | `/api/system-config/{key}` | Admin | Set one system config value. |
| `GET` | `/api/failure-logs` | Admin | Indexing failure log entries. |
| `POST` | `/api/occ/places-setup` | Admin | Trigger places / planet setup from the admin UI. |
| `GET` | `/api/recognize/api-key` | User | Recognize API key helper when Recognize is enabled. |

See also [OCC commands](occ-commands.md) and [system config](system-config.md).

## Other routes

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/static/{name}` | App static assets (e.g. processed `service-worker.js`, `go-vod` binary by `arch`). |

Vue deep-link pages (`/`, `/favorites`, `/folders/{path}`, …) are not part of the JSON API.

## Related source

| Area | Location |
|------|----------|
| Route table | `appinfo/routes.php` |
| Controllers | `lib/Controller/` |
| Timeline SQL | `lib/Db/TimelineQuery*.php` |
| Cluster backends | `lib/ClustersBackend/` |
| Frontend URL helpers | `src/services/API.ts` |
| Contract tests | `e2e/*.spec.ts`, `e2e/dataset-measurements.ts` |
