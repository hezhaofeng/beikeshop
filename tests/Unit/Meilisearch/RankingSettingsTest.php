<?php

namespace Tests\Unit\Meilisearch;

use Plugin\Meilisearch\Services\Settings;
use Tests\TestCase;

class RankingSettingsTest extends TestCase
{
    public function test_supported_ranking_fields_and_directions(): void
    {
        $this->assertSame('created_at', Settings::sortField(Settings::SORT_LATEST));
        $this->assertSame('views', Settings::sortField(Settings::SORT_VIEWS));
        $this->assertSame('sales', Settings::sortField(Settings::SORT_SALES));
        $this->assertSame('position', Settings::sortField(Settings::SORT_DEFAULT));
        $this->assertSame('updated_at', Settings::sortField(Settings::SORT_UPDATED));
        $this->assertNull(Settings::sortField(Settings::SORT_RELEVANCE));

        $this->assertSame('desc', Settings::sortOrder(Settings::SORT_LATEST));
        $this->assertSame('desc', Settings::sortOrder(Settings::SORT_VIEWS));
        $this->assertSame('asc', Settings::sortOrder(Settings::SORT_DEFAULT));
    }
}
