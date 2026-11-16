# Photos containing several people (Recognize)

Open a named or unnamed person in the Recognize People view and choose **Also with ...**.
Select another person to show only photos containing the original person **and every added person**.
Use the removable person buttons to remove a condition. Other people may also appear in a matching photo.
The selection is encoded in the page URL and survives reloads and back/forward navigation.

This initial implementation supports Recognize, not the separate Face Recognition backend.
It does not require reindexing, migrations, additional inference, or new dependencies.

## API

The existing timeline/day requests keep `recognize=user/person` as their primary person.
The optional `recognizePeople=12,34` parameter adds an intersection of up to eight additional
positive cluster IDs. Repeated IDs are collapsed. Invalid values return HTTP 400.
An ID that does not belong to the authenticated user matches no photos.
Both day counts and day details use the same transformation. File access restrictions remain
in the existing timeline query. Each extra person adds a correlated EXISTS predicate on indexed
detections and clusters, without multiplying the rows returned by the primary person query.

## Verification

Run the project checks from a development checkout inside a Nextcloud test installation:

```sh
make js-lint
npx prettier src --check
make php-lint
vendor/bin/phpunit --filter RecognizePeopleFilterTest
```

The SQL fixtures run in a separate in-memory SQLite database using SQL produced by Nextcloud's
query builder. They cover intersection, duplicate detections, user isolation, retained base
restrictions, and aggregate counts. Parser tests cover empty filters, duplicates, malformed IDs,
array parameters, oversized IDs, and the query-size limit.

Before release, check the UI with two and three people, no common photos, reload/back navigation,
and mobile widths against synthetic data. No end-to-end tests or production deployment are
implied by the static and unit checks. Existing single-person face rectangles and actions stay
associated with the original person.

Related upstream requests: #868 and #899.
