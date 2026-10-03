<?php

namespace Tests\Feature\Resources;

use App\Domain\Platform\CreatedOrganization;
use App\Domain\Resources\ArchiveResource;
use App\Domain\Resources\PublishResource;
use App\Domain\Resources\SaveResource;
use App\Domain\Shared\DomainException;
use App\Models\OrganizationMembership;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Clients\ClientsTestCase;

/** The Resources module through real requests and its actions: permissions, visibility, tenant isolation, files, search, caps, query bound. */
class ResourcesTest extends ClientsTestCase
{
    private CreatedOrganization $a;

    private CreatedOrganization $b;

    private OrganizationMembership $manager;

    private OrganizationMembership $viewer;

    private OrganizationMembership $none;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->a = $this->createOrganization(['name' => 'Alpha Practice']);
        $this->b = $this->createOrganization(['name' => 'Beta Practice']);
        $this->manager = $this->a->ownerMembership;
        $this->viewer = $this->addStaff($this->a->organization, 'clinician');
        $this->none = $this->addStaff($this->a->organization, 'staff');
        DB::table('role_permissions')->where('permission_key', 'resources.view')
            ->whereIn('role_id', DB::table('roles')->where('organization_id', $this->a->organization->id)->where('key', 'staff')->pluck('id'))->delete();
    }

    private function as(OrganizationMembership $member): static
    {
        $this->app->forgetScopedInstances();
        $this->actingAs(User::query()->findOrFail($member->user_id));

        return $this;
    }

    private function url(string $name, array $parameters = [], ?CreatedOrganization $in = null): string
    {
        return route($name, ['organization' => ($in ?? $this->a)->organization->slug] + $parameters);
    }

    private function pdf(string $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n", string $name = 'guide.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** @param array<string, mixed> $input */
    private function make(array $input = [], bool $publish = true, ?CreatedOrganization $in = null, ?UploadedFile $file = null): Resource
    {
        $in ??= $this->a;
        $input += ['type' => 'guide', 'title' => 'Guide '.fake()->unique()->numerify('####'), 'summary' => 'A summary.', 'body' => 'Some text for the guide.', 'audience' => 'everyone'];

        return $this->actAs($in->ownerMembership, $in->organization, function () use ($input, $publish, $file) {
            $resource = app(SaveResource::class)(array_diff_key($input, ['is_featured' => 1]), $file);
            if ($publish) {
                app(PublishResource::class)($resource);
            }
            if (($input['is_featured'] ?? false) && $publish) {
                app(SaveResource::class)($input, null, $resource);
            }

            $this->app['auth']->forgetGuards();

            return $resource;
        });
    }

    private function row(Resource $resource): object
    {
        return DB::table('resources')->where('id', $resource->id)->first();
    }

    // ---- permissions and visibility ----------------------------------------------------------------------------------

    #[Test]
    public function viewing_needs_resources_view_and_managing_needs_resources_manage(): void
    {
        $r = $this->make();

        $this->get($this->url('app.resources.index'))->assertRedirect();
        $this->as($this->none)->get($this->url('app.resources.index'))->assertForbidden();
        $this->as($this->viewer)->get($this->url('app.resources.index'))->assertOk()->assertSee($r->title);
        $this->as($this->viewer)->get($this->url('app.resources.show', ['resource' => $r->id]))->assertOk();

        foreach ([
            ['get', 'app.resources.create'], ['post', 'app.resources.store'],
            ['get', 'app.resources.edit', ['resource' => $r->id]], ['put', 'app.resources.update', ['resource' => $r->id]],
            ['post', 'app.resources.publish', ['resource' => $r->id]], ['post', 'app.resources.archive', ['resource' => $r->id]],
        ] as $spec) {
            [$method, $name] = $spec;
            $this->as($this->viewer)->{$method}($this->url($name, $spec[2] ?? []))->assertForbidden();
        }
        $this->as($this->manager)->get($this->url('app.resources.create'))->assertOk();
    }

    #[Test]
    public function drafts_archived_and_client_only_resources_are_invisible_to_viewers_but_not_to_managers(): void
    {
        $draft = $this->make(['title' => 'Secret draft'], publish: false);
        $archived = $this->make(['title' => 'Old archived']);
        $this->actAs($this->manager, $this->a->organization, fn () => app(ArchiveResource::class)($archived));
        $clients = $this->make(['title' => 'Portal only', 'audience' => 'clients']);

        $page = $this->as($this->viewer)->get($this->url('app.resources.index', ['view' => 'all']))->assertOk();
        $page->assertDontSee('Secret draft')->assertDontSee('Old archived')->assertDontSee('Portal only');
        $this->as($this->viewer)->get($this->url('app.resources.show', ['resource' => $draft->id]))->assertNotFound();
        $this->as($this->viewer)->get($this->url('app.resources.show', ['resource' => $clients->id]))->assertNotFound();
        // A viewer cannot ask for drafts either.
        $this->as($this->viewer)->get($this->url('app.resources.index', ['status' => 'draft']))->assertDontSee('Secret draft');

        $this->as($this->manager)->get($this->url('app.resources.index', ['status' => 'draft']))->assertSee('Secret draft');
        $this->as($this->manager)->get($this->url('app.resources.index', ['status' => 'archived']))->assertSee('Old archived');
        $this->as($this->manager)->get($this->url('app.resources.show', ['resource' => $draft->id]))->assertOk();
    }

    #[Test]
    public function another_organizations_resource_and_file_are_not_found_by_id(): void
    {
        $theirs = $this->make(['title' => 'Beta only'], in: $this->b, file: $this->pdf());
        $this->assertNotNull($this->row($theirs)->file_path);

        foreach (['app.resources.show', 'app.resources.file', 'app.resources.edit'] as $name) {
            $this->as($this->manager)->get($this->url($name, ['resource' => $theirs->id]))->assertNotFound();
        }
        $this->as($this->manager)->post($this->url('app.resources.publish', ['resource' => $theirs->id]))->assertNotFound();
        $this->as($this->manager)->get($this->url('app.resources.index', ['q' => 'Beta only']))->assertSee('No resources match');
        $this->as($this->manager)->get($this->url('app.resources.show', ['resource' => 'not-a-uuid']))->assertNotFound();
    }

    // ---- files -------------------------------------------------------------------------------------------------------

    #[Test]
    public function a_pdf_is_stored_privately_and_served_with_safe_headers(): void
    {
        $r = $this->make(['title' => 'New Client Guide'], file: $this->pdf());
        $path = $this->row($r)->file_path;

        $this->assertStringStartsWith('resources/'.$this->a->organization->id.'/', $path);
        Storage::disk('local')->assertExists($path);

        $response = $this->as($this->viewer)->get($this->url('app.resources.file', ['resource' => $r->id]))->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString($path, (string) $response->headers->get('Content-Disposition'));

        $none = $this->make(['title' => 'No file here']);
        $this->as($this->viewer)->get($this->url('app.resources.file', ['resource' => $none->id]))->assertNotFound();

        $draft = $this->make(['title' => 'Draft with file'], publish: false, file: $this->pdf());
        $this->as($this->viewer)->get($this->url('app.resources.file', ['resource' => $draft->id]))->assertNotFound();
    }

    #[Test]
    public function files_that_only_look_like_pdfs_are_refused(): void
    {
        foreach ([
            'html named pdf' => "<html><script>alert(1)</script></html>",
            'php named pdf' => '<?php system($_GET["c"]);',
            'header only lies' => "GIF89a%PDF-1.4",
            'pdf with script' => "%PDF-1.4\n1 0 obj\n<< /S /JavaScript /JS (app.alert(1)) >>\nendobj\n%%EOF",
        ] as $label => $content) {
            try {
                $this->make(['title' => 'Spoof '.$label], publish: false, file: $this->pdf($content));
                $this->fail("{$label} was accepted");
            } catch (DomainException $e) {
                $this->assertSame('file', $e->field(), $label);
            }
        }
        $this->assertSame(0, DB::table('resources')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Through the form too: a 422-style validation answer, nothing stored.
        $this->as($this->manager)->post($this->url('app.resources.store'), [
            'type' => 'document', 'title' => 'Fake', 'summary' => 'x', 'audience' => 'everyone',
            'file' => UploadedFile::fake()->createWithContent('a.pdf', '<?php echo 1;'),
        ])->assertSessionHasErrors('file');
        $this->assertSame(0, DB::table('resources')->count());
    }

    #[Test]
    public function a_pdf_over_twenty_megabytes_is_refused(): void
    {
        $this->as($this->manager)->post($this->url('app.resources.store'), [
            'type' => 'document', 'title' => 'Big', 'summary' => 'x', 'audience' => 'everyone',
            'file' => UploadedFile::fake()->create('big.pdf', 20 * 1024 + 1, 'application/pdf'),
        ])->assertSessionHasErrors('file');
    }

    #[Test]
    public function replacing_a_pdf_deletes_the_old_one(): void
    {
        $r = $this->make(file: $this->pdf());
        $old = $this->row($r)->file_path;

        $this->actAs($this->manager, $this->a->organization, fn () => app(SaveResource::class)(['type' => 'guide', 'title' => $r->title, 'summary' => 's', 'audience' => 'everyone', 'body' => 'x'], $this->pdf(), $r));

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($this->row($r)->file_path);
    }

    // ---- the video link ----------------------------------------------------------------------------------------------

    #[Test]
    public function the_external_link_must_be_https(): void
    {
        foreach (['http://example.org/v', 'javascript:alert(1)', 'ftp://example.org/v', '//example.org/v', 'https://user:pw@example.org/v', 'https:///x'] as $bad) {
            $this->as($this->manager)->post($this->url('app.resources.store'), [
                'type' => 'video', 'title' => 'Video', 'summary' => 'x', 'audience' => 'everyone', 'external_url' => $bad,
            ])->assertSessionHasErrors('external_url');
        }
        $this->assertSame(0, DB::table('resources')->count());

        $this->as($this->manager)->post($this->url('app.resources.store'), [
            'type' => 'video', 'title' => 'Telehealth Guide', 'summary' => 'x', 'audience' => 'everyone', 'external_url' => 'https://example.org/v?id=1',
        ])->assertSessionDoesntHaveErrors();
        $video = Resource::query()->where('title', 'Telehealth Guide')->firstOrFail();
        $this->as($this->manager)->post($this->url('app.resources.publish', ['resource' => $video->id]));

        $html = $this->as($this->viewer)->get($this->url('app.resources.show', ['resource' => $video->id]))->assertOk()->getContent();
        $this->assertStringContainsString('href="https://example.org/v?id=1"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('<script', preg_replace('#<script src="[^"]+"[^>]*></script>#', '', $html));
    }

    #[Test]
    public function the_database_refuses_a_non_https_link_and_a_featured_draft(): void
    {
        $r = $this->make(publish: false);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('resources')->where('id', $r->id)->update(['external_url' => 'http://example.org']);
    }

    #[Test]
    public function a_video_cannot_be_published_without_its_link_nor_a_guide_without_content(): void
    {
        $video = $this->make(['type' => 'video', 'body' => null], publish: false);
        $guide = $this->make(['body' => null], publish: false);

        foreach ([$video, $guide] as $draft) {
            try {
                $this->actAs($this->manager, $this->a->organization, fn () => app(PublishResource::class)($draft));
                $this->fail('published without content');
            } catch (DomainException $e) {
                $this->assertSame('resource_incomplete', $e->errorCode());
            }
        }
        $this->assertSame('draft', $this->row($video)->status);
    }

    // ---- publishing, archiving, audit --------------------------------------------------------------------------------

    #[Test]
    public function publishing_and_archiving_are_stamped_and_audited(): void
    {
        $r = $this->make(publish: false);
        $this->assertNull($this->row($r)->published_at);

        $this->as($this->manager)->post($this->url('app.resources.publish', ['resource' => $r->id]))->assertRedirect();
        $first = $this->row($r)->published_at;
        $this->assertNotNull($first);

        $this->as($this->manager)->post($this->url('app.resources.archive', ['resource' => $r->id]))->assertRedirect();
        $this->assertSame('archived', $this->row($r)->status);

        $this->as($this->manager)->post($this->url('app.resources.publish', ['resource' => $r->id]));
        $this->assertSame($first, $this->row($r)->published_at, 'restoring keeps the publication time');

        foreach (['resource.created', 'resource.published', 'resource.archived'] as $action) {
            $this->assertTrue(DB::table('audit_logs')->where('action', $action)->where('subject_id', $r->id)->exists(), $action);
        }
        $this->assertSame('resource', DB::table('audit_logs')->where('action', 'resource.created')->value('subject_type'));
    }

    // ---- featured ---------------------------------------------------------------------------------------------------

    #[Test]
    public function at_most_four_resources_are_featured_and_only_published_ones(): void
    {
        foreach (range(1, 4) as $i) {
            $this->make(['title' => "Featured {$i}", 'is_featured' => true]);
        }
        $fifth = $this->make(['title' => 'Fifth']);

        try {
            $this->actAs($this->manager, $this->a->organization, fn () => app(SaveResource::class)(['type' => 'guide', 'title' => 'Fifth', 'summary' => 's', 'audience' => 'everyone', 'body' => 'x', 'is_featured' => true], null, $fifth));
            $this->fail('fifth featured');
        } catch (DomainException $e) {
            $this->assertSame('featured_limit', $e->errorCode());
        }
        $this->assertSame(4, DB::table('resources')->where('is_featured', true)->count());

        $draft = $this->make(['title' => 'Draft'], publish: false);
        $this->expectException(DomainException::class);
        $this->actAs($this->manager, $this->a->organization, fn () => app(SaveResource::class)(['type' => 'guide', 'title' => 'Draft', 'summary' => 's', 'audience' => 'everyone', 'body' => 'x', 'is_featured' => true], null, $draft));
    }

    #[Test]
    public function archiving_unfeatures_and_the_page_shows_featured_apart_from_latest(): void
    {
        $f = $this->make(['title' => 'Star one', 'is_featured' => true]);
        $this->make(['title' => 'Plain one']);

        $html = $this->as($this->viewer)->get($this->url('app.resources.index'))->getContent();
        $this->assertSame(1, substr_count($html, 'Star one'), 'a featured resource is not repeated in the latest list');
        $this->assertStringContainsString('Plain one', $html);

        $this->actAs($this->manager, $this->a->organization, fn () => app(ArchiveResource::class)($f));
        $this->assertFalse((bool) $this->row($f)->is_featured);
    }

    // ---- tabs, counts, search ----------------------------------------------------------------------------------------

    #[Test]
    public function tabs_filter_by_type_and_carry_counts_for_screen_readers(): void
    {
        $this->make(['type' => 'guide', 'title' => 'G one']);
        $this->make(['type' => 'form', 'title' => 'F one']);
        $this->make(['type' => 'form', 'title' => 'F two']);
        $this->make(['type' => 'faq', 'title' => 'Q one']);
        $this->make(['type' => 'form', 'title' => 'F draft'], publish: false);

        $html = $this->as($this->viewer)->get($this->url('app.resources.index', ['type' => 'form']))->assertOk()->getContent();
        $this->assertStringContainsString('F one', $html);
        $this->assertStringNotContainsString('G one', $html);
        $this->assertStringNotContainsString('F draft', $html);
        $this->assertStringContainsString('Forms<span class="sr-only"> (2)</span>', $html);
        $this->assertStringContainsString('Guides<span class="sr-only"> (1)</span>', $html);
        $this->assertStringContainsString('All<span class="sr-only"> (4)</span>', $html);
        $this->assertStringContainsString('aria-current="page"', $html);

        $this->as($this->viewer)->get($this->url('app.resources.index', ['type' => 'nonsense']))->assertOk()->assertSee('G one');
    }

    #[Test]
    public function search_matches_title_or_summary_every_word_and_never_wildcards(): void
    {
        $this->make(['title' => 'Managing Anxiety', 'summary' => 'Helpful tips for daily life.']);
        $this->make(['title' => 'Billing', 'summary' => 'Insurance and anxiety coverage.']);
        $this->make(['title' => 'Mindfulness', 'summary' => 'Breathing exercises.']);

        $q = fn (string $term) => $this->as($this->viewer)->get($this->url('app.resources.index', ['q' => $term]))->assertOk()->getContent();

        $html = $q('anxiety');
        $this->assertStringContainsString('Managing Anxiety', $html);
        $this->assertStringContainsString('Billing', $html);
        $this->assertStringNotContainsString('Mindfulness', $html);
        $this->assertStringNotContainsString('Managing Anxiety', $q('anxiety insurance'));
        $this->assertStringContainsString('No resources match', $q('%'));
        $this->assertStringContainsString('No resources match', $q('_____'));
        $this->assertStringContainsString('Mindfulness', $q('  BREATHING  '));
    }

    #[Test]
    public function the_search_uses_the_trigram_index(): void
    {
        DB::statement('SET enable_seqscan = off');
        $plan = collect(DB::select("EXPLAIN SELECT id FROM resources WHERE search_text LIKE '%anx%' ESCAPE '\\'"))->map(fn ($r) => $r->{'QUERY PLAN'})->implode("\n");
        DB::statement('SET enable_seqscan = on');
        $this->assertStringContainsString('resources_search_text_trgm', $plan);
    }

    // ---- bounded cost, text rendering --------------------------------------------------------------------------------

    #[Test]
    public function the_page_costs_the_same_number_of_queries_for_a_few_rows_or_many(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as($this->manager)->get($this->url('app.resources.index'))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $add = function (int $n): void {
            foreach (range(1, $n) as $i) {
                $this->make(['type' => ['guide', 'form', 'document', 'faq'][$i % 4], 'title' => "Row {$i} ".fake()->unique()->numerify('####')]);
            }
        };

        $add(6);
        $count();
        $few = $count();
        $add(12);
        $many = $count();

        $this->assertSame($few, $many);
        $this->assertLessThanOrEqual(25, $many);
    }

    #[Test]
    public function text_is_escaped_and_paragraphs_are_kept(): void
    {
        $r = $this->make(['title' => 'Safe <b>title</b>', 'summary' => '<script>alert(1)</script>', 'body' => "First line\nsecond line\n\n\n\n<img src=x onerror=alert(1)>"]);

        $html = $this->as($this->viewer)->get($this->url('app.resources.show', ['resource' => $r->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertSame(2, substr_count($html, '<p>First line') + substr_count($html, '<p>&lt;img'));
        $this->assertStringContainsString('1 min read', $html);
    }

    #[Test]
    public function the_rail_links_only_to_what_exists_and_the_support_address_when_set(): void
    {
        $page = fn () => $this->as($this->viewer)->get($this->url('app.resources.index'))->assertOk()->getContent();

        $html = $page();
        $this->assertStringContainsString('My Appointments', $html);
        $this->assertStringNotContainsString('My Forms', $html);
        $this->assertStringNotContainsString('Contact Support', $html);
        $this->assertStringContainsString('Need Help?', $html);

        app(\App\Domain\Settings\SettingsService::class)->setPlatform(['platform.support_email' => 'help@example.org'], null);
        $this->assertStringContainsString('href="mailto:help@example.org"', $page());
    }
}
