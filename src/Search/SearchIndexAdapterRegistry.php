<?php

declare(strict_types=1);

namespace App\Search;

use App\Search\Index\ContentSearchIndexAdapter;
use App\Search\Index\ForumSearchIndexAdapter;
use App\Search\Index\GamingSearchIndexAdapter;
use App\Search\Index\VideoSearchIndexAdapter;

final readonly class SearchIndexAdapterRegistry
{
    public function __construct(
        private ContentSearchIndexAdapter $content,
        private VideoSearchIndexAdapter $video,
        private GamingSearchIndexAdapter $gaming,
        private ForumSearchIndexAdapter $forum,
    ) {
    }

    /** @return list<SearchIndexAdapter> */
    public function all(): array
    {
        return [$this->content, $this->video, $this->gaming, $this->forum];
    }
}
