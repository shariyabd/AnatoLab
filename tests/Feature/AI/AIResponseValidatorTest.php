<?php

declare(strict_types=1);

use App\Contracts\LearningContext;
use App\Services\AI\AIResponseValidator;
use Illuminate\Support\Facades\Log;

/*
| AIResponseValidator — docs/architecture.md §8.4, PRD §24.
|
| The last thing between a model and a 13-year-old.
*/

beforeEach(function (): void {
    $this->validator = new AIResponseValidator;

    $this->context = new LearningContext(
        userId: 7,
        educationLevel: 'high_school',
        difficultyPreference: 'intermediate',
        organName: 'Heart',
        structureName: 'Left ventricle',
    );
});

it('passes an ordinary educational answer through unchanged', function (): void {
    $answer = 'The left ventricle has a thicker wall because it pushes blood into the aorta '
        .'and around the whole body, which needs far more pressure than the short trip to the lungs.';

    $result = $this->validator->validate($answer, $this->context);

    expect($result->answer)->toBe($answer)
        ->and($result->replaced)->toBeFalse()
        ->and($result->violation)->toBeNull();
});

it('leaves teaching about disease alone', function (): void {
    // The failure mode a keyword blocklist produces: refusing to teach the
    // pathology a biology syllabus actually contains.
    $answer = 'In hypertension the left ventricle wall thickens over time, because it is '
        .'working against higher pressure with every beat.';

    expect($this->validator->validate($answer, $this->context)->replaced)->toBeFalse();
});

it('replaces an answer that claims to be a doctor', function (): void {
    Log::spy();

    $result = $this->validator->validate(
        'As your doctor, I would say the wall thickening is nothing to worry about.',
        $this->context,
    );

    expect($result->replaced)->toBeTrue()
        ->and($result->violation)->toBe('clinical_persona')
        ->and($result->answer)->toBe(AIResponseValidator::FALLBACK);
});

it('replaces an answer that diagnoses the reader', function (): void {
    Log::spy();

    $result = $this->validator->validate(
        'Based on your symptoms, you probably have a condition affecting the valve.',
        $this->context,
    );

    expect($result->replaced)->toBeTrue()
        ->and($result->violation)->toBe('clinical_diagnosis');
});

it('replaces an answer that recommends treatment or a dose', function (): void {
    Log::spy();

    expect($this->validator->validate('You need surgery to correct this.', $this->context)->violation)
        ->toBe('clinical_treatment')
        ->and($this->validator->validate('Take 20 mg each morning.', $this->context)->violation)
        ->toBe('clinical_treatment');
});

it('logs the reason code and the user, never the text', function (): void {
    // Never log a prompt, an API key, or a student's personal data
    // (docs/engineering.md §10).
    Log::spy();

    $this->validator->validate('I am a doctor and this is fine.', $this->context);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        function (string $message, array $payload): bool {
            return $payload['violation'] === 'clinical_persona'
                && $payload['user_id'] === 7
                && ! array_key_exists('answer', $payload)
                && ! array_key_exists('prompt', $payload);
        }
    );
});

it('enforces the length ceiling at a sentence boundary', function (): void {
    config(['ai.limits.max_response_words' => 20]);

    $long = str_repeat('The ventricle pumps blood onward. ', 20);
    $result = $this->validator->validate($long, $this->context);

    expect($result->trimmed)->toBeTrue()
        ->and($result->replaced)->toBeFalse()
        ->and(str_word_count($result->answer))->toBeLessThanOrEqual(20)
        // Cut at a full stop, not mid-clause.
        ->and($result->answer)->toEndWith('.');
});

it('cuts a truncated answer back to its last complete sentence', function (): void {
    $result = $this->validator->validate(
        'The wall is thicker. It has to generate enough pressure to reach the',
        $this->context,
        truncated: true,
    );

    expect($result->answer)->toBe('The wall is thicker.')
        ->and($result->trimmed)->toBeTrue();
});

it('falls back rather than showing an empty answer', function (): void {
    Log::spy();

    $result = $this->validator->validate('   ', $this->context);

    expect($result->replaced)->toBeTrue()
        ->and($result->violation)->toBe('empty_response');
});

it('offers the student something to do next in the fallback', function (): void {
    // PRD §40: never a bare failure a student cannot act on.
    expect(AIResponseValidator::FALLBACK)
        ->toContain('explain')
        ->not->toContain('error')
        ->not->toContain('provider');
});
