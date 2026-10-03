<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientListFilters;
use App\Domain\Clients\ClientSearchTerm;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClientListFiltersTest extends TestCase
{
    #[Test]
    public function the_defaults_show_active_and_inactive_clients_of_every_environment_by_last_name(): void
    {
        $filters = ClientListFilters::from([]);

        $this->assertSame('', $filters->q);
        $this->assertSame('open', $filters->status);
        $this->assertSame(['active', 'inactive'], $filters->statuses());
        $this->assertSame('all', $filters->records);
        $this->assertNull($filters->clinician);
        $this->assertSame('last_name', $filters->sort);
        $this->assertSame('asc', $filters->direction);
        $this->assertFalse($filters->isNarrowed());
    }

    #[Test]
    public function every_status_maps_to_the_statuses_it_allows(): void
    {
        $this->assertSame(['active'], ClientListFilters::from(['status' => 'active'])->statuses());
        $this->assertSame(['inactive'], ClientListFilters::from(['status' => 'inactive'])->statuses());
        $this->assertSame(['archived'], ClientListFilters::from(['status' => 'archived'])->statuses());
        $this->assertSame(['active', 'inactive', 'archived'], ClientListFilters::from(['status' => 'all'])->statuses());
        $this->assertSame(['active', 'inactive'], ClientListFilters::from(['status' => 'open'])->statuses());
    }

    #[Test]
    public function anything_unknown_or_malformed_falls_back_to_the_default(): void
    {
        $filters = ClientListFilters::from([
            'status' => 'deleted',
            'records' => 'everything',
            'sort' => 'password; drop table clients',
            'direction' => 'sideways',
            'clinician' => 'not-a-uuid',
            'q' => ['array', 'instead'],
        ]);

        $this->assertSame('open', $filters->status);
        $this->assertSame('all', $filters->records);
        $this->assertSame('last_name', $filters->sort);
        $this->assertSame('asc', $filters->direction);
        $this->assertNull($filters->clinician);
        $this->assertSame('', $filters->q);
    }

    #[Test]
    public function a_clinician_filter_is_a_membership_id_or_none(): void
    {
        $uuid = '0198a2f4-7c3e-7b1d-9a52-3f6a8c0d1e24';

        $this->assertSame($uuid, ClientListFilters::from(['clinician' => $uuid])->clinician);
        $this->assertSame('none', ClientListFilters::from(['clinician' => 'none'])->clinician);
        $this->assertNull(ClientListFilters::from(['clinician' => ''])->clinician);
    }

    #[Test]
    public function sort_and_direction_accept_their_known_values(): void
    {
        foreach (['last_name', 'client_number', 'created'] as $sort) {
            $this->assertSame($sort, ClientListFilters::from(['sort' => $sort])->sort);
        }
        $this->assertSame('desc', ClientListFilters::from(['direction' => 'DESC'])->direction);
        $this->assertSame('asc', ClientListFilters::from(['direction' => 'asc'])->direction);
    }

    #[Test]
    public function the_search_text_is_trimmed_and_capped(): void
    {
        $this->assertSame('ama', ClientListFilters::from(['q' => "  ama \n"])->q);
        $this->assertSame(ClientSearchTerm::MAX_LENGTH, mb_strlen(ClientListFilters::from(['q' => str_repeat('x', 1000)])->q));
    }

    #[Test]
    public function narrowing_is_detected_so_the_right_empty_state_can_be_shown(): void
    {
        $this->assertTrue(ClientListFilters::from(['q' => 'ama'])->isNarrowed());
        $this->assertTrue(ClientListFilters::from(['status' => 'archived'])->isNarrowed());
        $this->assertTrue(ClientListFilters::from(['records' => 'demo'])->isNarrowed());
        $this->assertTrue(ClientListFilters::from(['clinician' => 'none'])->isNarrowed());
        $this->assertFalse(ClientListFilters::from(['sort' => 'created', 'direction' => 'desc'])->isNarrowed(), 'sorting is not narrowing');
    }
}
