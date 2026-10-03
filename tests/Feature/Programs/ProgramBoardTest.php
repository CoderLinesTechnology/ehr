<?php

namespace Tests\Feature\Programs;

use App\Domain\Programs\DischargeClient;
use App\Domain\Programs\ProgramBoard;
use App\Domain\Programs\ProgramFilters;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\ScheduleProgramSession;
use App\Models\LevelOfCare;
use PHPUnit\Framework\Attributes\Test;

/** The overview's figures and their bounds. */
class ProgramBoardTest extends ProgramsTestCase
{
    private function board(): array
    {
        $this->app->forgetScopedInstances();

        return $this->doAs(fn () => app(ProgramBoard::class)(ProgramFilters::from([])));
    }

    #[Test]
    public function the_figures_follow_the_comps_definitions_and_count_live_participants_only(): void
    {
        $one = $this->program(['name' => 'One']);
        $two = $this->program(['name' => 'Two'], status: ProgramStatus::Upcoming);
        $done = $this->program(['name' => 'Done'], status: ProgramStatus::Completed);
        foreach (range(1, 3) as $i) {
            $this->admit($one, $this->client(), $this->level($one, "L{$i}", sort: $i));
        }
        $this->admit($two, $this->client());
        $this->admit($one, $this->client(demo: true), $this->level($one, 'Demo level', sort: 9));   // demo: never counted
        $finished = $this->admit($two, $this->client());
        $this->doAs(fn () => app(DischargeClient::class)($finished, 'completed'));

        foreach (['2026-10-06', '2026-10-09', '2026-10-30'] as $day) {
            $this->doAs(fn () => app(ScheduleProgramSession::class)($one, ['title' => "S {$day}", 'date' => $day, 'start_time' => '10:00', 'end_time' => '11:00', 'place' => 'online']));
        }

        $board = $this->board();

        $this->assertSame(2, $board['stats']['programs'], 'open programs: upcoming, active, on hold');
        $this->assertSame(4, $board['stats']['participants'], 'active live enrollments');
        $this->assertSame(2, $board['stats']['upcoming'], 'sessions starting within the next 7 days');
        $this->assertSame(1, $board['stats']['completed'], 'completed live enrollments');
        $this->assertEquals(['all' => 3, 'upcoming' => 1, 'active' => 1, 'on_hold' => 0, 'completed' => 1, 'archived' => 0], array_intersect_key($board['counts'], array_flip(['all', 'upcoming', 'active', 'on_hold', 'completed', 'archived'])));
        $this->assertCount(3, $board['schedule']);
        $this->assertSame('S 2026-10-06', $board['schedule'][0]['title']);
        $this->assertSame('L1', $board['cards']->getCollection()->firstWhere('name', 'One')->primaryLevel?->name, 'the first level by order is the card tag');
    }

    #[Test]
    public function the_overview_costs_the_same_queries_for_few_and_many_programs(): void
    {
        $this->app['config']->set('app.debug', false);
        $first = $this->program();
        $this->level($first);
        $this->admit($first, $this->client(), $this->level($first, 'L2'));
        $this->doAs(fn () => app(ScheduleProgramSession::class)($first, ['title' => 'S', 'date' => '2026-10-07', 'start_time' => '10:00', 'end_time' => '11:00', 'place' => 'online']));

        $count = function (): int {
            $this->app->forgetScopedInstances();
            [, $sql] = $this->recordingQueries(fn () => $this->doAs(fn () => app(ProgramBoard::class)(ProgramFilters::from([]))));

            return count($sql);
        };
        $count(); // warm the entitlement/settings memos
        $few = $count();

        foreach (range(1, 8) as $i) {
            $program = $this->program();
            $level = $this->level($program, "Level {$i}");
            $this->admit($program, $this->client(), $level);
            $this->doAs(fn () => app(ScheduleProgramSession::class)($program, ['title' => "S{$i}", 'date' => '2026-10-08', 'start_time' => '10:00', 'end_time' => '11:00', 'place' => 'online']));
        }
        $many = $count();

        $this->assertSame($few, $many, 'no query per card');
        $this->assertLessThanOrEqual(10, $many);
    }

    #[Test]
    public function the_page_itself_has_a_constant_query_count(): void
    {
        $measure = function (): int {
            $this->app->forgetScopedInstances();
            [$response, $sql] = $this->recordingQueries(fn () => $this->as($this->admin)->get($this->url('app.programs.index')));
            $response->assertOk();

            return count($sql);
        };

        $p = $this->program();
        $this->admit($p, $this->client());
        $measure();
        $few = $measure();
        foreach (range(1, 6) as $i) {
            $q = $this->program();
            $this->level($q, "L{$i}");
            $this->admit($q, $this->client(), LevelOfCare::query()->where('program_id', $q->id)->first());
        }
        $this->assertSame($few, $measure());
    }
}
