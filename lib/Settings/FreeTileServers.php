<?php

declare(strict_types=1);

namespace OCA\Memories\Settings;

/**
 * Default list of free map tile servers.
 *
 * Each entry has a 'name' shown in the user settings, a tile 'url'
 * with Leaflet placeholders {s}, {z}, {x} and {y}, an 'attribution'
 * shown on the map, a 'maxZoom' for the tile layer and a 'csp' list
 * of domains allow-listed in the content security policy.
 */
final class FreeTileServers
{
    public const TILES = [
        [
            'name' => 'OpenStreetMap',
            'url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
            'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            'maxZoom' => 19,
            'csp' => ['https://tile.openstreetmap.org'],
        ],
        [
            'name' => 'OpenStreetMap DE',
            'url' => 'https://tile.openstreetmap.de/{z}/{x}/{y}.png',
            'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            'maxZoom' => 18,
            'csp' => ['https://tile.openstreetmap.de'],
        ],
        [
            'name' => 'Esri Street',
            'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}',
            'attribution' => 'Powered by Esri',
            'maxZoom' => 19,
            'csp' => ['https://server.arcgisonline.com'],
        ],
        [
            'name' => 'Esri Satellite',
            'url' => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            'attribution' => 'Powered by Esri',
            'maxZoom' => 19,
            'csp' => ['https://server.arcgisonline.com'],
        ],
        [
            'name' => 'OpenTopoMap',
            'url' => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
            'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, <a href="http://viewfinderpanoramas.org">SRTM</a> | &copy; <a href="https://opentopomap.org">OpenTopoMap</a>',
            'maxZoom' => 17,
            'csp' => ['https://*.tile.opentopomap.org'],
        ],
    ];
}
