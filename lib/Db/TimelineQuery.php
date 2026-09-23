<?php

declare(strict_types=1);

namespace OCA\Memories\Db;

final class TimelineQuery
{
    use TimelineQueryBase;
    use TimelineQueryDays;
    use TimelineQueryFilters;
    use TimelineQueryFolders;
    use TimelineQueryLivePhoto;
    use TimelineQueryMap;
    use TimelineQueryNativeX;
    use TimelineQuerySingleItem;
}
