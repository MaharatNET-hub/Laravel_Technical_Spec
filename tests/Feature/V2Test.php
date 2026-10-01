<?php

namespace Tests\Feature;

use App\Mail\V2\NewSubmission;
use App\Mail\V2\ReviewIssued;
use App\Mail\V2\SubmissionReceived;
use App\Models\User;
use App\Models\V2\Message;
use App\Models\V2\Submission;
use App\Pdf\ContentWalker;
use App\Pdf\Reader;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class V2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // a fresh storage folder per test: the v2 installer runs (migrate + seed) on the first request
        $dir = sys_get_temp_dir() . '/sr-v2-' . getmypid() . '-' . uniqid();
        @mkdir($dir . '/app', 0777, true);
        @mkdir($dir . '/framework/views', 0777, true);
        $this->app->useStoragePath($dir);
        config(['v2.admin_email' => 'admin@test.local', 'v2.admin_password' => 'Secret-Pass-123', 'v2.locale' => 'ar']);
        Mail::fake();
    }

    private function submit(): Submission
    {
        $pdf = ReviewPipelineTest::submittal();
        $code = $this->postJson('/v2/submit', [
            'client_name' => 'Sara Haddad', 'client_company' => 'Pioneer Dynamics Switchgear', 'client_email' => 'sara@example.com',
            'project_name' => 'City Walk Phase 5', 'discipline' => 'Electrical', 'title' => 'LV switchgear', 'file_name' => 'sub.pdf', 'file_size' => strlen($pdf),
        ])->assertOk()->json('code');
        $parts = str_split($pdf, 1500);
        foreach ($parts as $i => $part) {
            $res = $this->call('POST', "/v2/submit/$code/chunk?index=$i&total=" . count($parts), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $part);
            $res->assertOk();
        }
        for ($n = 0; $n < 50 && ! $this->postJson("/v2/submit/$code/step")->assertOk()->json('done'); $n++) {
        }

        return Submission::where('code', $code)->firstOrFail();
    }

    public function test_public_pages_in_both_languages(): void
    {
        foreach (['/v2', '/v2/about', '/v2/services', '/v2/projects', '/v2/projects/hospital-retrofit', '/v2/contact', '/v2/submit', '/v2/track'] as $url) {
            $this->get($url)->assertOk()->assertSee('dir="rtl"', false);
        }
        $this->get('/v2?lang=en')->assertOk()->assertSee('dir="ltr"', false)->assertSee('Atlas Engineering Consultants');
        $this->get('/v2/projects/does-not-exist')->assertNotFound();
    }

    public function test_v1_is_untouched(): void
    {
        $this->get('/')->assertOk()->assertSee('Submittal Review');
        $this->get('/api/state')->assertOk()->assertJsonStructure(['hide', 'project', 'rules']);
    }

    public function test_contact_form_reaches_the_inbox(): void
    {
        $this->post('/v2/contact', ['name' => 'Omar', 'email' => 'omar@example.com', 'body' => 'Call me'])->assertRedirect('/v2/contact');
        $this->assertSame(1, Message::count());
        $this->post('/v2/contact', ['name' => 'Bot', 'email' => 'b@example.com', 'body' => 'spam', 'website' => 'x']);
        $this->assertSame(1, Message::count()); // honeypot
    }

    public function test_submission_is_uploaded_analysed_and_tracked(): void
    {
        $s = $this->submit();
        $this->assertSame('review', $s->status);
        $this->assertSame(6, $s->page_count);
        $this->assertSame(4, $s->comment_count); // R1, R4, R5, R6 (no linked submittal in v2)
        $this->assertSame('Revise / Resubmit', $s->decision);
        Mail::assertSent(SubmissionReceived::class, fn ($m) => $m->hasTo('sara@example.com'));
        Mail::assertSent(NewSubmission::class);
        $this->get("/v2/track/{$s->code}")->assertOk()->assertDontSee(route('v2.track.download', $s->code));
        $this->get("/v2/track/{$s->code}/download")->assertNotFound(); // not issued yet

        // only the visitor who created it may upload to it
        $this->flushSession();
        $this->call('POST', "/v2/submit/{$s->code}/chunk?index=0&total=1", [], [], [], [], '%PDF-1.4')->assertForbidden();
    }

    public function test_admin_reviews_issues_and_emails(): void
    {
        $s = $this->submit();
        $this->get('/v2/admin')->assertRedirect('/v2/admin/login');
        $this->get("/v2/admin/submissions/{$s->id}/api/state")->assertRedirect('/v2/admin/login');

        $this->post('/v2/admin/login', ['email' => 'admin@test.local', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/v2/admin/login', ['email' => 'admin@test.local', 'password' => 'Secret-Pass-123'])->assertRedirect();
        $this->assertAuthenticated();
        foreach (['/v2/admin', '/v2/admin/submissions', "/v2/admin/submissions/{$s->id}", "/v2/admin/submissions/{$s->id}/workspace", '/v2/admin/messages',
            '/v2/admin/projects', '/v2/admin/projects/create', '/v2/admin/services', '/v2/admin/company', '/v2/admin/settings', '/v2/admin/submissions/export'] as $url) {
            $this->get($url)->assertOk();
        }

        // the analysis tool's API, scoped to this submission; client details hidden by default
        $state = $this->getJson("/v2/admin/submissions/{$s->id}/api/state")->assertOk()->json();
        $this->assertTrue($state['hide']);
        $this->assertStringNotContainsString('City Walk', json_encode($state));

        // email refused while the review is a draft
        $this->post("/v2/admin/submissions/{$s->id}/email", [])->assertSessionHas('bad');

        $this->postJson("/v2/admin/submissions/{$s->id}/api/generate", ['comments' => $state['review']['comments'], 'decision' => 'Revise / Resubmit', 'engineer' => 'Eng. Khaled'])->assertOk();
        $s->refresh();
        $this->assertSame('issued', $s->status);
        $this->assertSame('Eng. Khaled', $s->engineer);

        // the issued PDF does not contain the client's names (from the drawings or from the form)
        $r = Reader::open($s->outputPath());
        $text = '';
        foreach ($r->pages() as $p) {
            $w = new ContentWalker($r);
            $w->page($p['dict'], $p['resources']);
            $text .= implode(' ', array_column($w->items, 's'));
        }
        $this->assertDoesNotMatchRegularExpression('/City Walk|Pioneer|Sara Haddad/i', $text);

        $this->post("/v2/admin/submissions/{$s->id}/email", ['note' => 'Please resubmit'])->assertSessionHas('bad'); // array mailer = not really sent: warns
        Mail::assertSent(ReviewIssued::class, fn ($m) => $m->hasTo('sara@example.com') && $m->attached && count($m->attachments()) === 1);
        $this->assertNotNull($s->fresh()->emailed_at);

        // the client can now download it
        $this->flushSession();
        $this->get("/v2/track/{$s->code}/download")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_admin_edits_site_content(): void
    {
        $this->get('/v2'); // install
        $this->actingAs(User::first());
        $this->put('/v2/admin/company', ['co' => ['name_en' => 'New Name Ltd', 'name_ar' => 'اسم جديد'], 'c' => ['email' => 'hi@new.test'], 'stats' => []])->assertSessionHas('ok');
        $this->post('/v2/admin/projects', ['title_en' => 'Bridge', 'title_ar' => 'جسر', 'active' => 1, 'accent' => '#123456'])->assertRedirect('/v2/admin/projects');
        $this->post('/v2/admin/services', ['title_en' => 'Audits', 'title_ar' => 'تدقيق', 'icon' => 'chart', 'active' => 1])->assertRedirect('/v2/admin/services');
        $this->get('/v2?lang=en')->assertSee('New Name Ltd')->assertSee('hi@new.test');
        $this->get('/v2/projects/bridge')->assertOk()->assertSee('Bridge');
        $this->get('/v2/services?lang=en')->assertSee('Audits');
    }
}
