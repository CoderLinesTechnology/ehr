<?php

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\AddParticipants;
use App\Domain\Messaging\ConversationKind;
use App\Domain\Messaging\InboxReader;
use App\Domain\Messaging\LeaveGroup;
use App\Domain\Messaging\MarkRead;
use App\Domain\Messaging\React;
use App\Domain\Messaging\RetractMessage;
use App\Domain\Messaging\SendMessage;
use App\Domain\Messaging\StartConversation;
use App\Domain\Messaging\ThreadReader;
use App\Domain\Messaging\UnreadCount;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Shared\DomainException;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\OrganizationMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Clients\ClientsTestCase;

/** Messaging: access, isolation, retraction, attachments, unread/read, reactions, demo consistency, bounds, audit. */
class MessagingTest extends ClientsTestCase
{
    private CreatedOrganization $a;

    private CreatedOrganization $b;

    private OrganizationMembership $ann;

    private OrganizationMembership $ben;

    private OrganizationMembership $cy;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->a = $this->createOrganization(['name' => 'Alpha Practice']);
        $this->b = $this->createOrganization(['name' => 'Beta Practice']);
        $org = $this->a->organization;
        $this->ann = $this->addStaff($org, 'clinician');
        $this->ben = $this->addStaff($org, 'clinician');
        $this->cy = $this->addStaff($org, 'clinician');
    }

    private function org()
    {
        return $this->a->organization;
    }

    private function url(string $name, array $p = []): string
    {
        return route($name, ['organization' => $this->org()->slug] + $p);
    }

    private function as(OrganizationMembership $m): static
    {
        $this->app->forgetScopedInstances();
        $this->actingAs(User::query()->findOrFail($m->user_id));

        return $this;
    }

    private function direct(OrganizationMembership $from, OrganizationMembership $to): Conversation
    {
        return $this->actAs($from, $this->org(), fn () => app(StartConversation::class)($from, ConversationKind::Direct, [$to->id]));
    }

    private function say(OrganizationMembership $m, Conversation $c, string $body, ?UploadedFile $file = null): Message
    {
        return $this->actAs($m, $this->org(), fn () => app(SendMessage::class)($m, $c, $body, $file));
    }

    private function png(string $name = 'scan.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    #[Test]
    public function non_participants_get_404_on_every_conversation_route(): void
    {
        $c = $this->direct($this->ann, $this->ben);
        $m = $this->say($this->ann, $c, 'private words');
        $file = $this->say($this->ann, $c, '', $this->png());
        $att = DB::table('message_attachments')->where('message_id', $file->id)->value('id');

        $this->as($this->cy);
        $this->get($this->url('app.messages.show', ['conversation' => $c->id]))->assertNotFound();
        $this->getJson($this->url('app.messages.poll', ['conversation' => $c->id]))->assertNotFound();
        $this->post($this->url('app.messages.send', ['conversation' => $c->id]), ['body' => 'hi'])->assertNotFound();
        $this->post($this->url('app.messages.read', ['conversation' => $c->id]))->assertNotFound();
        $this->post($this->url('app.messages.react', ['conversation' => $c->id, 'message' => $m->id]), ['emoji' => '👍'])->assertNotFound();
        $this->post($this->url('app.messages.retract', ['conversation' => $c->id, 'message' => $m->id]))->assertNotFound();
        $this->get($this->url('app.messages.attachments.show', ['attachment' => $att]))->assertNotFound();

        $this->as($this->ben)->get($this->url('app.messages.show', ['conversation' => $c->id]))->assertOk()->assertSee('private words');
    }

    #[Test]
    public function another_organizations_conversation_message_and_attachment_ids_are_404(): void
    {
        $bStaff = $this->addStaff($this->b->organization, 'clinician');
        $bStaff2 = $this->addStaff($this->b->organization, 'clinician');
        $bConv = $this->actAs($bStaff, $this->b->organization, fn () => app(StartConversation::class)($bStaff, ConversationKind::Direct, [$bStaff2->id]));
        $bMsg = $this->actAs($bStaff, $this->b->organization, fn () => app(SendMessage::class)($bStaff, $bConv, '', $this->png()));
        $bAtt = DB::table('message_attachments')->where('message_id', $bMsg->id)->value('id');

        $this->as($this->ann);
        $this->get($this->url('app.messages.show', ['conversation' => $bConv->id]))->assertNotFound();
        $this->get($this->url('app.messages.attachments.show', ['attachment' => $bAtt]))->assertNotFound();
        $this->post($this->url('app.messages.retract', ['conversation' => $bConv->id, 'message' => $bMsg->id]))->assertNotFound();
        // The same ids under the member's own organization's slug are still unknown.
        $this->assertSame(0, $this->actAs($this->ann, $this->org(), fn () => Conversation::query()->whereKey($bConv->id)->count()));
        // A member of A cannot reach B's URL at all.
        $this->get(route('app.messages.index', ['organization' => $this->b->organization->slug]))->assertStatus(404);
    }

    #[Test]
    public function client_threads_need_messages_client_and_visibility_of_that_client(): void
    {
        $mine = $this->clientIn($this->org(), ['primary_clinician_membership_id' => $this->ann->id]);
        $theirs = $this->clientIn($this->org(), ['primary_clinician_membership_id' => $this->ben->id]);

        $c = $this->actAs($this->ann, $this->org(), fn () => app(StartConversation::class)($this->ann, ConversationKind::Client, [], null, $mine));
        $this->assertSame($mine->id, $c->client_id);
        $this->assertSame('live', $c->record_environment->value);

        // Ann may not open a thread about a client she cannot see.
        $this->expectException(DomainException::class);
        try {
            $this->actAs($this->ann, $this->org(), fn () => app(StartConversation::class)($this->ann, ConversationKind::Client, [], null, $theirs));
        } finally {
            $this->as($this->ann)->get($this->url('app.messages.show', ['conversation' => $c->id]))->assertOk();
            // Visibility lost (client reassigned): the thread disappears for her.
            $this->inTenant($this->org(), fn () => Client::query()->whereKey($mine->id)->update(['primary_clinician_membership_id' => $this->ben->id]));
            $this->app['auth']->forgetGuards();
            $this->as($this->ann)->get($this->url('app.messages.show', ['conversation' => $c->id]))->assertNotFound();
            // Permission lost: 403 on the inbox.
            $this->revokeFromRole($this->org(), 'clinician', 'messages.client');
            $this->revokeFromRole($this->org(), 'clinician', 'messages.send');
            $this->as($this->ann)->get($this->url('app.messages.index'))->assertForbidden();
        }
    }

    #[Test]
    public function a_sender_can_retract_for_five_minutes_leaving_a_tombstone_and_an_audit_row_without_the_words(): void
    {
        $c = $this->direct($this->ann, $this->ben);
        CarbonImmutable::setTestNow('2026-03-02 10:00:00');
        $m = $this->say($this->ann, $c, 'secret diagnosis talk');

        $this->expectException(DomainException::class);
        try {
            // Someone else cannot.
            try {
                $this->actAs($this->ben, $this->org(), fn () => app(RetractMessage::class)($this->ben, $m));
                $this->fail('allowed');
            } catch (DomainException) {
            }

            CarbonImmutable::setTestNow('2026-03-02 10:04:00');
            $this->actAs($this->ann, $this->org(), fn () => app(RetractMessage::class)($this->ann, $m));
            $row = DB::table('messages')->where('id', $m->id)->first();
            $this->assertSame('', $row->body);
            $this->assertNotNull($row->retracted_at);
            $this->as($this->ben)->get($this->url('app.messages.show', ['conversation' => $c->id]))->assertSee('This message was removed')->assertDontSee('secret diagnosis');

            $audit = DB::table('audit_logs')->whereIn('action', ['message.sent', 'message.retracted'])->get();
            $this->assertCount(2, $audit);
            $this->assertStringNotContainsString('secret', json_encode($audit));

            // The database refuses any rewrite or delete.
            try {
                DB::table('messages')->where('id', $m->id)->update(['body' => 'edited']);
                $this->fail('rewrite allowed');
            } catch (QueryException) {
            }
        } finally {
            // Window closed.
            CarbonImmutable::setTestNow('2026-03-02 10:00:00');
            $late = $this->say($this->ann, $c, 'late');
            CarbonImmutable::setTestNow('2026-03-02 10:06:00');
            $this->actAs($this->ann, $this->org(), fn () => app(RetractMessage::class)($this->ann, $late));
        }
    }

    #[Test]
    public function messages_are_never_deleted(): void
    {
        $c = $this->direct($this->ann, $this->ben);
        $m = $this->say($this->ann, $c, 'x');
        $this->expectException(QueryException::class);
        DB::table('messages')->where('id', $m->id)->delete();
    }

    #[Test]
    public function attachments_are_sniffed_stored_privately_and_served_only_to_participants(): void
    {
        $c = $this->direct($this->ann, $this->ben);

        // A script renamed to .png is refused: the bytes decide.
        $fake = UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1; ?>');
        try {
            $this->say($this->ann, $c, 'x', $fake);
            $this->fail('spoof accepted');
        } catch (DomainException $e) {
            $this->assertSame('attachment', $e->field());
        }
        $html = UploadedFile::fake()->createWithContent('page.pdf', '<html><script>alert(1)</script></html>');
        try {
            $this->say($this->ann, $c, 'x', $html);
            $this->fail('html accepted');
        } catch (DomainException) {
        }
        $this->assertSame(0, DB::table('messages')->count());

        $m = $this->say($this->ann, $c, 'see attached', $this->png('../../etc/passwd.png'));
        $row = DB::table('message_attachments')->where('message_id', $m->id)->first();
        $this->assertSame('image/png', $row->mime);
        $this->assertStringStartsWith('messages/', $row->storage_path);
        $this->assertStringNotContainsString('..', $row->original_name);
        Storage::disk('local')->assertExists($row->storage_path);

        $response = $this->as($this->ben)->get($this->url('app.messages.attachments.show', ['attachment' => $row->id]));
        $response->assertOk();
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        // The attachment's own isolating policy survives the global security headers; only the framing rule is added.
        $this->assertSame("default-src 'none'; sandbox; frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));

        $this->as($this->cy)->get($this->url('app.messages.attachments.show', ['attachment' => $row->id]))->assertNotFound();
        $this->app['auth']->forgetGuards();
        auth()->logout();
        $this->get($this->url('app.messages.attachments.show', ['attachment' => $row->id]))->assertRedirect();
    }

    #[Test]
    public function unread_counts_read_markers_and_receipts(): void
    {
        $c = $this->direct($this->ann, $this->ben);
        $this->say($this->ann, $c, 'one');
        $last = $this->say($this->ann, $c, 'two');
        $this->say($this->ben, $c, 'mine');

        $unread = fn (OrganizationMembership $m) => $this->actAs($m, $this->org(), fn () => UnreadCount::for($m));
        $this->assertSame(1, $unread($this->ann));   // Ben's reply
        $this->assertSame(0, $unread($this->ben));   // his own send marked the earlier ones read

        // Ann reads: her badge clears, and Ben's message shows a read receipt only for what she has read.
        $this->actAs($this->ann, $this->org(), fn () => app(MarkRead::class)($this->ann, $c));
        $this->assertSame(0, $unread($this->ann));
        $page = $this->actAs($this->ben, $this->org(), fn () => app(ThreadReader::class)->page($c, $this->ben));
        $byBody = collect($page->messages)->keyBy('body');
        $this->assertTrue($byBody['mine']->read);
        $this->assertFalse($byBody['one']->read, 'only own messages carry receipts');

        // A new message from Ann is unread for Ben and not yet read by Ben.
        $this->say($this->ann, $c, 'three');
        $this->assertSame(1, $unread($this->ben));
        $page = $this->actAs($this->ann, $this->org(), fn () => app(ThreadReader::class)->page($c, $this->ann));
        $this->assertFalse(collect($page->messages)->firstWhere('body', 'three')->read);
        $this->actAs($this->ben, $this->org(), fn () => app(MarkRead::class)($this->ben, $c));
        $page = $this->actAs($this->ann, $this->org(), fn () => app(ThreadReader::class)->page($c, $this->ann));
        $this->assertTrue(collect($page->messages)->firstWhere('body', 'three')->read);
        $this->assertNotNull($last);
    }

    #[Test]
    public function reactions_come_from_an_allowlist_one_per_participant_and_toggle(): void
    {
        $c = $this->direct($this->ann, $this->ben);
        $m = $this->say($this->ann, $c, 'hello');
        $react = fn (OrganizationMembership $who, string $e) => $this->actAs($who, $this->org(), fn () => app(React::class)($who, $m, $e));

        try {
            $react($this->ben, '💀');
            $this->fail('not allowed');
        } catch (DomainException) {
        }
        $this->assertNotNull($react($this->ben, '👍'));
        $react($this->ben, '❤️');                       // replaces
        $this->assertSame(1, DB::table('message_reactions')->where('message_id', $m->id)->count());
        $this->assertSame('❤️', DB::table('message_reactions')->value('emoji'));
        $this->assertNull($react($this->ben, '❤️'));    // same again removes
        $this->assertSame(0, DB::table('message_reactions')->count());
        try {
            $react($this->cy, '👍');
            $this->fail('outsider reacted');
        } catch (DomainException) {
        }
        $this->expectException(QueryException::class);
        DB::table('message_reactions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $this->org()->id, 'conversation_id' => $c->id, 'message_id' => $m->id, 'membership_id' => $this->ann->id, 'emoji' => '💀']);
    }

    #[Test]
    public function a_demo_clients_thread_is_demo_and_the_database_keeps_it_so(): void
    {
        $demo = $this->demoClientIn($this->org(), ['primary_clinician_membership_id' => $this->ann->id]);
        $c = $this->actAs($this->ann, $this->org(), fn () => app(StartConversation::class)($this->ann, ConversationKind::Client, [], null, $demo));
        $this->assertSame('demo', $c->record_environment->value);

        $this->expectException(QueryException::class);
        DB::table('conversations')->where('id', $c->id)->update(['record_environment' => 'live']);
    }

    #[Test]
    public function leaving_a_group_ends_access_and_groups_can_grow(): void
    {
        $g = $this->actAs($this->ann, $this->org(), fn () => app(StartConversation::class)($this->ann, ConversationKind::Group, [$this->ben->id], 'Team'));
        $this->say($this->ann, $g, 'before cy');
        $added = $this->actAs($this->ann, $this->org(), fn () => app(AddParticipants::class)($this->ann, $g, [$this->cy->id]));
        $this->assertSame(1, $added);
        $this->as($this->cy)->get($this->url('app.messages.show', ['conversation' => $g->id]))->assertOk()->assertDontSee('before cy');

        $this->actAs($this->ben, $this->org(), fn () => app(LeaveGroup::class)($this->ben, $g));
        $this->as($this->ben)->get($this->url('app.messages.show', ['conversation' => $g->id]))->assertNotFound();
        $this->assertSame(1, DB::table('messages')->where('sender_membership_id', $this->ann->id)->count());
        $this->assertSame(0, $this->actAs($this->ben, $this->org(), fn () => app(InboxReader::class)->page($this->ben)->rows === [] ? 0 : 1));
    }

    #[Test]
    public function the_inbox_and_thread_use_a_bounded_number_of_queries_and_search_ignores_bodies(): void
    {
        $measure = function (int $threads) {
            for ($i = 0; $i < $threads; $i++) {
                $other = $this->addStaff($this->org(), 'clinician');
                $c = $this->direct($this->ann, $other);
                $this->say($other, $c, 'needle-in-body');
            }
            $this->app->forgetScopedInstances();
            [, $q] = $this->recordingQueries(fn () => $this->actAs($this->ann, $this->org(), fn () => app(InboxReader::class)->page($this->ann)));

            return count($q);
        };
        $few = $measure(3);
        $many = $measure(12);
        $this->assertLessThanOrEqual($few + 1, $many, "inbox queries grew: {$few} -> {$many}");
        $this->assertLessThanOrEqual(12, $many);

        $this->assertSame([], $this->actAs($this->ann, $this->org(), fn () => app(InboxReader::class)->page($this->ann, 'all', 'needle')->rows));

        $c = $this->direct($this->ann, $this->ben);
        foreach (range(1, 5) as $i) {
            $this->say($this->ann, $c, "m{$i}");
        }
        $count = function () use ($c) {
            [, $q] = $this->recordingQueries(fn () => $this->actAs($this->ann, $this->org(), fn () => app(ThreadReader::class)->page($c, $this->ann)));

            return count($q);
        };
        $small = $count();
        foreach (range(6, 60) as $i) {
            $this->say($this->ann, $c, "m{$i}");
        }
        $this->assertSame($small, $count());
        $page = $this->actAs($this->ann, $this->org(), fn () => app(ThreadReader::class)->page($c, $this->ann));
        $this->assertCount(50, $page->messages);
        $this->assertNotNull($page->earlier);
        $older = $this->actAs($this->ann, $this->org(), fn () => app(ThreadReader::class)->page($c, $this->ann, $page->earlier));
        $this->assertCount(10, $older->messages);
        $this->assertNull($older->earlier);
    }

    #[Test]
    public function search_finds_a_conversation_by_participant_name(): void
    {
        $other = $this->addStaff($this->org(), 'clinician', [], User::factory()->create(['name' => 'Zebediah Quill']));
        $this->direct($this->ann, $other);
        $this->direct($this->ann, $this->ben);
        $rows = $this->actAs($this->ann, $this->org(), fn () => app(InboxReader::class)->page($this->ann, 'all', 'zebed')->rows);
        $this->assertCount(1, $rows);
        $this->assertSame('Zebediah Quill', $rows[0]->name);
    }

    #[Test]
    public function sending_validates_length_and_leaves_no_words_in_audit_rows(): void
    {
        $c = $this->direct($this->ann, $this->ben);
        try {
            $this->say($this->ann, $c, str_repeat('a', 5001));
            $this->fail('too long');
        } catch (DomainException) {
        }
        try {
            $this->say($this->ann, $c, '   ');
            $this->fail('empty');
        } catch (DomainException) {
        }
        $this->say($this->ann, $c, str_repeat('b', 5000));
        $this->say($this->ann, $c, 'patient Jane Doe has panic attacks');
        $this->assertStringNotContainsString('Jane', json_encode(DB::table('audit_logs')->get()));

        $this->as($this->ann)->post($this->url('app.messages.send', ['conversation' => $c->id]), ['body' => 'via http'])->assertRedirect();
        $this->assertTrue(DB::table('messages')->where('body', 'via http')->exists());
    }

    #[Test]
    public function presence_is_stamped_at_most_once_a_minute(): void
    {
        CarbonImmutable::setTestNow('2026-03-02 10:00:00');
        $this->as($this->ann)->get($this->url('app.messages.index'))->assertOk();
        $first = DB::table('users')->where('id', $this->ann->user_id)->value('last_seen_at');
        $this->assertNotNull($first);
        CarbonImmutable::setTestNow('2026-03-02 10:00:30');
        $this->as($this->ann)->get($this->url('app.messages.index'));
        $this->assertSame($first, DB::table('users')->where('id', $this->ann->user_id)->value('last_seen_at'));
        CarbonImmutable::setTestNow('2026-03-02 10:01:30');
        $this->as($this->ann)->get($this->url('app.messages.index'));
        $this->assertNotSame($first, DB::table('users')->where('id', $this->ann->user_id)->value('last_seen_at'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }
}
