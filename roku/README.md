# Memories for Roku

Apache-2.0 (same as `android/`). Native BrightScript / SceneGraph client for [Memories](https://memories.gallery) on Nextcloud.

Connects with a **public share link** (simplest) or a Nextcloud **app password**. Does not modify Memories PHP/Vue.

## Features

- Paste a Memories folder (`/s/...`) or album (`/a/...`) share URL
- Or sign in with server URL + username + app password
- Mode picker after connect:
  - **Slideshow (newest first)** — timeline order, newest → older
  - **Random slideshow** — shuffled playlist (reshuffles when looping)
  - **Timeline browser** — pick a day, then browse that day manually
- Back from slideshow returns to the mode menu (not reconnect)
- Options (`*`) or **Change connection** to re-enter setup
- Last mode persisted in the device registry

Photos only (no video yet). Folder deep-browse is not in v1: a share token already scopes to that folder/album; account mode uses the full timeline.

## Photo picking

| Mode | Source | Order |
|------|--------|--------|
| Newest slideshow | `/api/days?nopreload=1` then day details (≤60 days, ≤400 photos) | API default: **newest first** (`dayid` / `datetaken` DESC). Same for share and account. |
| Random | Same load as newest | Fisher–Yates shuffle client-side; reshuffle on loop |
| Timeline day | `/api/days/{dayid}` | Newest-first within the day; Left/Right only (no auto-advance) |

The Memories `reverse` query param flips to oldest-first; this app does not pass it for the shipped modes.

## Requirements

- Roku device or [Roku Emulator](https://developer.roku.com/) with developer mode enabled
- Nextcloud with Memories installed
- For share mode: a public folder or album share from Memories

## Sideload

1. Enable Developer Mode on the Roku (Settings → System → Developer).
2. Note the device IP and the developer password shown on that screen.
3. From this directory:

```bash
./package.sh
curl --noproxy '*' --digest -u 'rokudev:<DEVELOPER_PASSWORD>' -sS \
  -F "mysubmit=Install" -F "archive=@memories-roku.zip" \
  "http://<ROKU_IP>/plugin_install"
```

Or zip manually:

```bash
zip -r memories-roku.zip manifest LICENSE README.md source components images
```

Then open `http://<ROKU_IP>` in a browser, log in as `rokudev`, and upload the zip.

## Onboarding

### Share link (recommended)

1. In Memories / Nextcloud, create a public share of a folder or album.
2. On the Roku app, choose **Share link** and paste the URL, for example:
   - `https://cloud.example.com/apps/memories/s/AbCdEf`
   - `https://cloud.example.com/apps/memories/a/XyZ123`
3. Select **Connect** → choose a mode.

### Account

1. In Nextcloud: Personal settings → Security → create an **App password**.
2. On the Roku app, choose **Account**.
3. Enter the Nextcloud base URL, username, and app password → **Connect** → choose a mode.

## Controls

| Remote | Action |
|--------|--------|
| Left / Right / OK | Previous / next photo (slideshow) |
| Back | Modes menu (or timeline list from a day view) |
| Options (`*`) | Change connection |
| Replay | Reload timeline / restart day |

## Layout

```
roku/
  manifest
  LICENSE
  README.md
  package.sh
  source/          shared BrightScript (config, API)
  components/      SceneGraph screens + ApiTask
  images/          channel icons / splash
```

## Privacy

Share tokens and app passwords are stored in the Roku registry section `memories_roku` on the device only.
