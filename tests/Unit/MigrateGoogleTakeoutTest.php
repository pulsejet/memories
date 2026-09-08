<?php

declare(strict_types=1);

namespace OCA\Memories\Tests\Unit;

use OCA\Memories\Command\MigrateGoogleTakeout;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \OCA\Memories\Command\MigrateGoogleTakeout
 */
final class MigrateGoogleTakeoutTest extends TestCase
{
    public function testFullMetadata(): void
    {
        $json = [
            'title' => 'IMG_20210101_120000.jpg',
            'description' => 'A wonderful day in San Francisco',
            'imageViews' => '42',
            'creationTime' => [
                'timestamp' => '1609500000',
                'formatted' => 'Jan 1, 2021, 11:20:00 AM UTC',
            ],
            'photoTakenTime' => [
                'timestamp' => '1609459200',
                'formatted' => 'Jan 1, 2021, 12:00:00 AM UTC',
            ],
            'geoData' => [
                'latitude' => 37.774929,
                'longitude' => -122.419416,
                'altitude' => 15.5,
                'latitudeSpan' => 0.0,
                'longitudeSpan' => 0.0,
            ],
            'geoDataExif' => [
                'latitude' => 37.774929,
                'longitude' => -122.419416,
                'altitude' => 15.5,
                'latitudeSpan' => 0.0,
                'longitudeSpan' => 0.0,
            ],
            'url' => 'https://photos.google.com/photo/12345',
            'googlePhotosOrigin' => [
                'mobileUpload' => [
                    'deviceFolder' => [
                        'localFolderName' => 'Camera',
                    ],
                    'deviceType' => 'ANDROID_PHONE',
                ],
            ],
        ];

        $res = $this->takeoutToExiftoolJson($json);

        self::assertSame('A wonderful day in San Francisco', $res['Description'] ?? null);
        self::assertSame('2021:01:01 00:00:00+0000', $res['AllDates'] ?? null);
        self::assertSame(37.774929, $res['GPSLatitude'] ?? null);
        self::assertSame(-122.419416, $res['GPSLongitude'] ?? null);
        self::assertSame(15.5, $res['GPSAltitude'] ?? null);
    }

    public function testZeroAndEmptyValuesAreOmitted(): void
    {
        $json = [
            'title' => 'IMG_0001.jpg',
            'description' => '',
            'photoTakenTime' => [
                'timestamp' => '0',
            ],
            'geoData' => [
                'latitude' => 0.0,
                'longitude' => 0.0,
                'altitude' => 0.0,
            ],
        ];

        $res = $this->takeoutToExiftoolJson($json);

        self::assertSame([], $res);
    }

    public function testPartialMetadata(): void
    {
        $json = [
            'description' => 'Only description and timestamp',
            'photoTakenTime' => [
                'timestamp' => '1672531199',
            ],
        ];

        $res = $this->takeoutToExiftoolJson($json);

        self::assertSame([
            'Description' => 'Only description and timestamp',
            'AllDates' => '2022:12:31 23:59:59+0000',
        ], $res);
    }

    public function testEmptyInput(): void
    {
        $res = $this->takeoutToExiftoolJson([]);

        self::assertSame([], $res);
    }

    public function testFindTakeoutJsonFile(): void
    {
        $dir = '/takeout/2024';

        // Full suffix
        self::assertSame(
            $dir.'/IMG_0001.jpg.supplemental-metadata.json',
            $this->findTakeoutJsonFile($dir.'/IMG_0001.jpg', [
                $dir.'/IMG_0002.jpg.supplemental-metadata.json',
                $dir.'/IMG_0001.jpg.supplemental-metadata.json',
            ]),
        );

        // Truncated suffix variants from the issue report
        self::assertSame(
            $dir.'/Screenshot_2017-07-05-10-05-26.png.supplementa.json',
            $this->findTakeoutJsonFile($dir.'/Screenshot_2017-07-05-10-05-26.png', [
                $dir.'/Screenshot_2017-07-05-10-05-26.png.supplementa.json',
            ]),
        );
        self::assertSame(
            $dir.'/Screenshot_20241227_105539_Edge.jpg.supplement.json',
            $this->findTakeoutJsonFile($dir.'/Screenshot_20241227_105539_Edge.jpg', [
                $dir.'/Screenshot_20241227_105539_Edge.jpg.supplement.json',
            ]),
        );
        self::assertSame(
            $dir.'/D7157814-D2D5-4A62-AFCE-9CF465CB7D66.jpeg.supp.json',
            $this->findTakeoutJsonFile($dir.'/D7157814-D2D5-4A62-AFCE-9CF465CB7D66.jpeg', [
                $dir.'/D7157814-D2D5-4A62-AFCE-9CF465CB7D66.jpeg.supp.json',
            ]),
        );

        // Truncated base name (suffix dropped entirely by Google)
        self::assertSame(
            $dir.'/Screenshot_20220207_102421_com.snapchat.androi.json',
            $this->findTakeoutJsonFile($dir.'/Screenshot_20220207_102421_com.snapchat.android.jpg', [
                $dir.'/Screenshot_20220207_102421_com.snapchat.androi.json',
            ]),
        );

        // No match: unrelated file, short-name prefix trap, other directory
        self::assertNull($this->findTakeoutJsonFile($dir.'/IMG_0001.jpg', [
            $dir.'/OTHER.jpg.supplemental-metadata.json',
        ]));
        self::assertNull($this->findTakeoutJsonFile($dir.'/Screenshot_2024.jpg', [
            $dir.'/Screenshot_2.json',
        ]));
        self::assertNull($this->findTakeoutJsonFile($dir.'/IMG_0001.jpg', [
            '/takeout/other/IMG_0001.jpg.supplemental-metadata.json',
        ]));
        self::assertNull($this->findTakeoutJsonFile($dir.'/IMG_0001.jpg', [
            $dir.'/IMG_0001.jpg',
        ]));
    }

    private function findTakeoutJsonFile(string $path, array $candidatePaths): ?string
    {
        $method = new \ReflectionMethod(MigrateGoogleTakeout::class, 'findTakeoutJsonFile');

        /** @var ?string */
        return $method->invoke(null, $path, $candidatePaths);
    }

    private function takeoutToExiftoolJson(array $json): array
    {
        $method = new \ReflectionMethod(MigrateGoogleTakeout::class, 'takeoutToExiftoolJson');

        return (array) $method->invoke(null, $json);
    }
}
