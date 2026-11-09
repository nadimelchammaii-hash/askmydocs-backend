<?php

use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentPage;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\PdfFixture;

/*
 * "Try the demo": a temporary account with copies of the prepared samples, which
 * belong to a hidden template account.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake(config('documents.disk'));
});

afterEach(function () {
    PdfFixture::cleanUp();
});

/** The template account with processed samples (as DemoSeeder leaves it). */
function demoTemplate(int $samples = 2): User
{
    $template = User::factory()->create(['email' => config('demo.template_email')]);

    foreach (range(1, $samples) as $number) {
        $sample = Document::factory()->for($template)->ready(2)->create([
            'title' => "Sample {$number}",
            'is_sample' => true,
            'suggested_questions' => ["Question about sample {$number}?"],
        ]);
        Storage::disk(config('documents.disk'))->put($sample->storage_path, "%PDF-1.7 sample {$number}");
        DocumentPage::factory()->for($sample)->createMany([['page_number' => 1], ['page_number' => 2]]);
        DocumentChunk::factory()->for($sample)->createMany([
            ['chunk_index' => 0, 'page_number' => 1],
            ['chunk_index' => 1, 'page_number' => 2],
        ]);
    }

    return $template;
}

function startDemo(): TestResponse
{
    return test()->fromSpa()->postJson('/api/v1/auth/demo');
}

describe('starting a demo', function () {
    it('signs the visitor in to a new demo account with copies of the samples', function () {
        $template = demoTemplate();
        $this->freezeSecond();

        startDemo()->assertCreated()
            ->assertJsonPath('data.name', 'Demo visitor')
            ->assertJsonPath('data.is_demo', true)
            ->assertJsonPath('data.demo_expires_at', now()->addHours(24)->toIso8601String());

        $demo = User::query()->whereNotNull('demo_expires_at')->sole();
        expect($demo->email)->toEndWith('@demo.askmydocs.invalid');
        $this->assertAuthenticatedAs($demo);

        $documents = $this->getJson('/api/v1/documents')->assertOk()->json('data');
        expect(collect($documents)->pluck('title')->sort()->values()->all())->toBe(['Sample 1', 'Sample 2'])
            ->and(collect($documents)->pluck('is_sample')->unique()->all())->toBe([true])
            ->and(collect($documents)->pluck('status')->unique()->all())->toBe(['ready'])
            ->and(collect($documents)->sortBy('title')->pluck('suggested_questions')->values()->all())
            ->toBe([['Question about sample 1?'], ['Question about sample 2?']]);

        // Pages, chunks (with their embeddings) and the PDF file are copied, not shared.
        foreach ($demo->documents as $copy) {
            $sample = $template->documents()->where('title', $copy->title)->sole();
            expect($copy->id)->not->toBe($sample->id)
                ->and($copy->storage_path)->toBe("{$demo->id}/{$copy->id}.pdf")
                ->and($copy->pages()->pluck('text')->all())->toBe($sample->pages()->pluck('text')->all())
                ->and($copy->chunks()->get()->map->only(['chunk_index', 'page_number', 'content', 'embedding'])->all())
                ->toEqual($sample->chunks()->get()->map->only(['chunk_index', 'page_number', 'content', 'embedding'])->all());
            Storage::disk(config('documents.disk'))->assertExists($copy->storage_path);
        }

        // Ready at once: nothing is queued, so no OpenAI call is made.
        Queue::assertNothingPushed();
    });

    it('gives every visitor their own copies', function () {
        demoTemplate(1);

        startDemo()->assertCreated();
        $this->post('/api/v1/auth/logout');
        startDemo()->assertCreated();

        expect(User::query()->whereNotNull('demo_expires_at')->count())->toBe(2)
            ->and(Document::query()->where('is_sample', true)->count())->toBe(3); // template + 2 copies
    });

    it('only copies samples that finished processing', function () {
        $template = demoTemplate(1);
        Document::factory()->for($template)->create(['title' => 'Still processing']);

        startDemo()->assertCreated();

        expect(collect($this->getJson('/api/v1/documents')->json('data'))->pluck('title')->all())->toBe(['Sample 1']);
    });

    it('is unavailable without prepared samples, with a message for the visitor', function () {
        startDemo()->assertServiceUnavailable()->assertExactJson([
            'message' => 'The demo is not available right now. Please try again later.',
            'code' => 'demo_unavailable',
        ]);

        expect(User::count())->toBe(0);
        $this->assertGuest();
    });

    it('is unavailable when switched off', function () {
        demoTemplate(1);
        config(['demo.enabled' => false]);

        startDemo()->assertServiceUnavailable()->assertJsonPath('code', 'demo_unavailable');
    });

    it('limits new demos per visitor per hour', function () {
        demoTemplate(1);
        config(['demo.starts_per_hour' => 2]);

        startDemo()->assertCreated();
        startDemo()->assertCreated();
        startDemo()->assertTooManyRequests();

        $this->travel(61)->minutes();
        startDemo()->assertCreated();
    });

    it('caps new demos for the whole site per day', function () {
        demoTemplate(1);
        config(['demo.starts_per_hour' => 10, 'demo.starts_per_day' => 1]);

        startDemo()->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        startDemo()->assertServiceUnavailable()->assertExactJson([
            'message' => 'The demo is very busy today. Please try again tomorrow.',
            'code' => 'demo_unavailable',
        ]);
    });
});

describe('inside a demo', function () {
    beforeEach(function () {
        demoTemplate(1);
        startDemo()->assertCreated();
        $this->demo = User::query()->whereNotNull('demo_expires_at')->sole();
        $this->sample = $this->demo->documents()->sole();
    });

    it('cannot rename or delete the samples', function () {
        $this->patchJson("/api/v1/documents/{$this->sample->id}", ['title' => 'Mine now'])
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->deleteJson("/api/v1/documents/{$this->sample->id}")->assertForbidden();

        expect($this->sample->fresh()->title)->toBe('Sample 1');
    });

    it('can read the samples and ask questions about them', function () {
        $this->getJson("/api/v1/documents/{$this->sample->id}/file")->assertOk();
        $this->postJson("/api/v1/documents/{$this->sample->id}/conversations", ['question' => 'What is it about?'])
            ->assertAccepted();

        expect(Conversation::query()->where('user_id', $this->demo->id)->count())->toBe(1);
    });

    it('can upload one PDF of its own, which it may rename and delete', function () {
        $upload = fn (string $name) => $this->postJson('/api/v1/documents', [
            'file' => PdfFixture::upload($name, PdfFixture::bytes(['Visitor document text.'])),
        ]);

        $own = $upload('mine.pdf')->assertAccepted()->json('data');
        expect($own['is_sample'])->toBeFalse()
            ->and($own['suggested_questions'])->toBeNull();

        $upload('second.pdf')->assertForbidden()->assertJsonPath('code', 'demo_upload_limit');

        $this->patchJson("/api/v1/documents/{$own['id']}", ['title' => 'Renamed'])->assertOk();
        $this->deleteJson("/api/v1/documents/{$own['id']}")->assertNoContent();
    })->skip(fn () => ! popplerAvailable(), 'Poppler (pdfinfo) is not installed');

    it('cannot change the account settings', function () {
        $this->patchJson('/api/v1/user', ['name' => 'Visitor'])->assertForbidden()->assertJsonPath('code', 'demo_account');
        $this->putJson('/api/v1/user/password', [
            'current_password' => 'anything',
            'password' => 'New-horse-battery-7',
            'password_confirmation' => 'New-horse-battery-7',
        ])->assertForbidden()->assertJsonPath('code', 'demo_account');
    });

    it('has a smaller daily question limit, shown in the stats', function () {
        config(['demo.questions_per_day' => 2, 'answers.questions_per_day' => 100]);

        foreach ([1, 2] as $number) {
            $this->postJson("/api/v1/documents/{$this->sample->id}/conversations", ['question' => "Question {$number}?"])->assertAccepted();
        }

        $this->postJson("/api/v1/documents/{$this->sample->id}/conversations", ['question' => 'Question 3?'])
            ->assertTooManyRequests()->assertJsonPath('code', 'daily_limit_reached');

        $this->getJson('/api/v1/stats')->assertJsonPath('data.questions_today.limit', 2)
            ->assertJsonPath('data.questions_today.remaining', 0);
    });
});

describe('a real account', function () {
    it('keeps its normal limits and settings', function () {
        $user = User::factory()->create();
        config(['demo.questions_per_day' => 2, 'answers.questions_per_day' => 100]);

        $this->fromSpa()->actingAs($user)->getJson('/api/v1/stats')->assertJsonPath('data.questions_today.limit', 100);
        $this->fromSpa()->actingAs($user)->getJson('/api/v1/user')->assertJsonPath('data.is_demo', false);
        $this->fromSpa()->actingAs($user)->patchJson('/api/v1/user', ['name' => 'Still mine'])->assertOk();
    });
});

describe('demo:prune', function () {
    it('deletes expired demo accounts with their documents and files, and nothing else', function () {
        $template = demoTemplate(1);
        $realUser = User::factory()->create();
        $realDocument = Document::factory()->for($realUser)->ready()->create();
        Storage::disk(config('documents.disk'))->put($realDocument->storage_path, '%PDF-1.7 real');

        startDemo()->assertCreated();
        $expired = User::query()->whereNotNull('demo_expires_at')->sole();
        $expiredFile = $expired->documents()->sole()->storage_path;
        $this->postJson("/api/v1/documents/{$expired->documents()->sole()->id}/conversations", ['question' => 'Hello?']);
        $this->post('/api/v1/auth/logout');

        $this->travel(25)->hours();
        startDemo()->assertCreated(); // still running: started after the first one expired
        $running = User::query()->whereNotNull('demo_expires_at')->latest('id')->first();

        $this->artisan('demo:prune')->expectsOutput('Deleted 1 expired demo account.')->assertSuccessful();

        expect(User::find($expired->id))->toBeNull()
            ->and(Document::query()->where('user_id', $expired->id)->exists())->toBeFalse()
            ->and(Conversation::query()->where('user_id', $expired->id)->exists())->toBeFalse()
            ->and(User::find($running->id))->not->toBeNull()
            ->and(User::find($realUser->id))->not->toBeNull()
            ->and($template->documents()->count())->toBe(1);
        Storage::disk(config('documents.disk'))->assertMissing($expiredFile);
        Storage::disk(config('documents.disk'))->assertExists($realDocument->storage_path);
        Storage::disk(config('documents.disk'))->assertExists($template->documents()->sole()->storage_path);
    });
});

it('never emails password reset links to demo addresses', function () {
    Notification::fake();
    demoTemplate(1);
    startDemo()->assertCreated();
    $demo = User::query()->whereNotNull('demo_expires_at')->sole();
    $this->post('/api/v1/auth/logout');

    $this->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => $demo->email])->assertOk();
    $this->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => config('demo.template_email')])->assertOk();

    Notification::assertNothingSent();
});

it('sends reset links to normal addresses as before', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@example.com'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});
