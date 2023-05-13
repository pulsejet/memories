---
description: Getting help with Memories
---

# Help and FAQ

## Getting Help

If you have any questions, feel free to reach out at

- [Discord community](https://discord.gg/7Dr9f9vNjJ) (any questions, feedback, suggestions, etc.)
- [GitHub issues](https://github.com/pulsejet/memories/issues) (bugs and feature requests)

## FAQ

**How is it different from the default Nextcloud Photos app?**

You can find a full comparison [here](../memories-vs-photos).

**What apps does Memories compete against?**

Commercial cloud photo services. The target is to be better than `X` service provider that you pay $$$ for, and be usable by grandma.

**Is it production ready?**

Yes.

**Does it support multiple users and external sharing?**

Yes.

**Why is it tied to Nextcloud? Isn't this a lot of overhead? Isn't PHP slow?**

1.  Reinventing the wheel is pointless. If Memories was a dedicated app, that would mean re-implementing everything from automatic file upload to multi-user support and auth integrations. The maintenance overhead of such a codebase increases exponentially, all while completely unnecessary since someone else is maintaining the exact same things in another piece of software. Integrating with Nextcloud is what makes Memories sustainable.
1.  PHP and Nextcloud have become very fast over the last few years, and running both is very minimal overhead. Functions such as upgrading Nextcloud
    to newer versions is seamless especially when using Docker.
1.  The power of Memories is integration: the Nextcloud ecosystem provides tons of other apps for extending functionality.

**Why doesn't it support `<some-feature>` such as XMP tags and advanced metadata editing?**

The target user of Memories is not a tech-savvy self-hoster. Most commonly used / available features will be given priority over advanced features, e.g. most useful for professionals / photographers / data hoarders. That doesn't mean to say these features will necessarily not be implemented.

**Does Memories support a folder structure for storage?**

Yes. All photos are stored in a folder structure, and only displayed as a flat timeline. This means you can swap out Memories for any other photo app if you want (no lock-in). You can also view the photos in the folder structure if you desire.

**Does it have a mobile app?**

Not yet. The web app is very responsive on mobile. You can use the official Nextcloud app to auto-upload photos and videos from your device.

**How is it better than the `Y` FOSS photo manager?**

UX and performance. The devil is in the details.

**It's slow or doesn't work**

Make sure you follow the [configuration steps](../config). Unless you have hundreds of thousands of photos on a Raspberry Pi, Memories should be very fast. File an issue otherwise.

**It says "nothing to show here" on startup?**

Indexing is performed in the background, and can take a while depending on the number of photos. Follow
the [configuration steps](../config) and be patient.

**Will it run on my system?**

In general, if you can run Nextcloud, you should be able to run Memories. File an issue if you run into problems.

**How to completely remove Memories? Maybe to reinstall after errors?**

Uninstall Memories from the app store, then run the following SQL on your database.

```sql
DROP TABLE IF EXISTS oc_memories;
DROP TABLE IF EXISTS oc_memories_livephoto;
DROP TABLE IF EXISTS oc_memories_mapclusters;
DROP TABLE IF EXISTS oc_memories_places;
DROP TABLE IF EXISTS oc_memories_planet;
DROP TABLE IF EXISTS memories_planet_geometry;
DROP INDEX IF EXISTS memories_parent_mimetype ON oc_filecache;
DELETE FROM oc_migrations WHERE app='memories';
```

On Postgres, the syntax for dropping the index is:

```sql
DROP INDEX IF EXISTS memories_parent_mimetype;
```
