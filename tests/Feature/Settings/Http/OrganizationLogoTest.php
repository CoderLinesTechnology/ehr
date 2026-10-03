<?php

namespace Tests\Feature\Settings\Http;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class OrganizationLogoTest extends SettingsHttpTestCase
{
    private const MARKER = 'SECRET-GPS-MARKER-4711';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function png(int $w = 64, int $h = 64, bool $withMetadata = false): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 160, 150));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        if ($withMetadata) { // a tEXt chunk right after IHDR, as cameras and editors leave them
            $data = 'Comment'."\0".self::MARKER;
            $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));
            $bytes = substr($bytes, 0, 33).$chunk.substr($bytes, 33);
        }

        return $bytes;
    }

    private function jpeg(bool $withMetadata = false): string
    {
        $image = imagecreatetruecolor(80, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 90, 40));
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();

        if ($withMetadata) { // a COM segment after SOI
            $bytes = substr($bytes, 0, 2)."\xFF\xFE".pack('n', strlen(self::MARKER) + 2).self::MARKER.substr($bytes, 2);
        }

        return $bytes;
    }

    private function upload(string $bytes, string $name = 'logo.png', string $mime = 'image/png'): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent($name, $bytes);

        return $this->post($this->path('organization/logo'), ['logo' => new UploadedFile($file->getPathname(), $name, $mime, null, true)]);
    }

    private function storedFiles(?Organization $organization = null): array
    {
        return Storage::disk('local')->allFiles('organizations/'.($organization ?? $this->org)->id);
    }

    #[Test]
    public function a_png_is_stored_privately_under_a_random_name_and_audited(): void
    {
        $this->asAdmin();
        $this->upload($this->png())->assertRedirect($this->path('organization'))->assertSessionHas('success');

        $org = Organization::query()->findOrFail($this->org->id);
        $this->assertMatchesRegularExpression('#^organizations/'.$org->id.'/logo-[a-z0-9]{24}\.png$#', (string) $org->logo_path);
        Storage::disk('local')->assertExists($org->logo_path);
        $this->assertNotNull(AuditLog::query()->where('action', 'organization.logo_updated')->where('organization_id', $org->id)->first());
    }

    #[Test]
    public function the_logo_is_served_to_members_only_with_private_caching_and_no_sniffing(): void
    {
        $this->asAdmin();
        $this->upload($this->png());

        $response = $this->get($this->path('organization/logo'))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $response->headers->get('Cache-Control'));

        $this->assertTrue(str_starts_with($response->streamedContent(), "\x89PNG"));
    }

    #[Test]
    public function another_organization_cannot_fetch_the_logo_and_sees_only_its_own(): void
    {
        $this->asAdmin();
        $this->upload($this->png());

        $this->asOtherAdmin();
        $this->assertContains($this->get($this->path('organization/logo'))->getStatusCode(), [403, 404], 'a stranger may not fetch it by the other organization URL');
        $this->get($this->path('organization/logo', $this->other))->assertNotFound(); // and the other organization has none of its own

        $this->assertSame([], $this->storedFiles($this->other));
        $this->assertNull(Organization::query()->findOrFail($this->other->id)->logo_path);
    }

    #[Test]
    public function a_logo_url_reaches_the_organization_page(): void
    {
        $this->asAdmin();
        $this->upload($this->png());

        $this->get($this->path('organization'))->assertSee($this->path('organization/logo'), false);
    }

    #[Test]
    public function metadata_is_stripped_by_re_encoding(): void
    {
        $this->asAdmin();

        foreach ([['png', $this->png(64, 64, true), 'image/png'], ['jpg', $this->jpeg(true), 'image/jpeg']] as [$ext, $bytes, $mime]) {
            $this->assertStringContainsString(self::MARKER, $bytes);
            $this->upload($bytes, "logo.$ext", $mime)->assertSessionHasNoErrors();

            $stored = Storage::disk('local')->get(Organization::query()->findOrFail($this->org->id)->logo_path);
            $this->assertStringNotContainsString(self::MARKER, $stored, "{$ext} metadata must not survive");
            $this->assertNotFalse(@imagecreatefromstring($stored));
        }
    }

    #[Test]
    public function code_appended_to_an_image_does_not_survive(): void
    {
        $this->asAdmin();
        $this->upload($this->png().'<?php system($_GET["c"]); ?>')->assertSessionHasNoErrors();

        $stored = Storage::disk('local')->get(Organization::query()->findOrFail($this->org->id)->logo_path);
        $this->assertStringNotContainsString('<?php', $stored);
    }

    #[Test]
    public function files_that_only_pretend_to_be_images_are_refused_by_content(): void
    {
        $this->asAdmin();
        $cases = [
            'php with a png name and type' => ['<?php echo 1;', 'logo.png', 'image/png'],
            'html with a jpg name' => ['<html><script>alert(1)</script></html>', 'logo.jpg', 'image/jpeg'],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.png', 'image/png'],
            'gif renamed to png' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'logo.png', 'image/png'],
            'truncated png header only' => ["\x89PNG\r\n\x1a\n", 'logo.png', 'image/png'],
        ];

        foreach ($cases as $label => [$bytes, $name, $mime]) {
            $this->from($this->path('organization'))->post($this->path('organization/logo'), ['logo' => new UploadedFile(
                UploadedFile::fake()->createWithContent($name, $bytes)->getPathname(), $name, $mime, null, true,
            )])->assertSessionHasErrors('logo');
            $this->assertSame([], $this->storedFiles(), "{$label}: nothing may be stored");
        }

        $this->assertNull(Organization::query()->findOrFail($this->org->id)->logo_path);
    }

    #[Test]
    public function a_real_image_with_a_misleading_name_is_judged_by_its_content(): void
    {
        $this->asAdmin();
        $this->upload($this->png(), 'logo.svg', 'image/svg+xml')->assertSessionHasNoErrors();

        $this->assertStringEndsWith('.png', (string) Organization::query()->findOrFail($this->org->id)->logo_path);
    }

    #[Test]
    public function oversized_files_and_oversized_images_are_refused(): void
    {
        $this->asAdmin();

        $this->from($this->path('organization'));
        $this->upload($this->png().str_repeat('0', 1_048_576))->assertSessionHasErrors('logo');
        $this->upload($this->png(1025, 8))->assertSessionHasErrors('logo');
        $this->upload($this->png(8, 1025))->assertSessionHasErrors('logo');
        $this->assertSame([], $this->storedFiles());

        $this->upload($this->png(1024, 1024))->assertSessionHasNoErrors(); // the limits are inclusive
    }

    #[Test]
    public function a_missing_file_is_a_field_error(): void
    {
        $this->asAdmin()->from($this->path('organization'))->post($this->path('organization/logo'), [])->assertSessionHasErrors('logo');
    }

    #[Test]
    public function replacing_the_logo_deletes_the_previous_file(): void
    {
        $this->asAdmin();
        $this->upload($this->png());
        $first = Organization::query()->findOrFail($this->org->id)->logo_path;

        $this->upload($this->jpeg(), 'again.jpg', 'image/jpeg');
        $second = Organization::query()->findOrFail($this->org->id)->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        $this->assertCount(1, $this->storedFiles());
    }

    #[Test]
    public function only_someone_who_manages_settings_may_upload_while_any_member_may_view_it(): void
    {
        $this->asAdmin();
        $this->upload($this->png());

        $this->memberWith(['clients.view']);
        $this->upload($this->png())->assertForbidden();
        $this->get($this->path('organization/logo'))->assertOk();
    }
}
