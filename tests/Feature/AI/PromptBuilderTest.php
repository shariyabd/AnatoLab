<?php

declare(strict_types=1);

use App\Contracts\LearningContext;
use App\Contracts\RetrievedChunk;
use App\Enums\TutorTask;
use App\Services\AI\PromptBuilder;

/*
| PromptBuilder — PRD §39's context block, docs/architecture.md §8.4's rules,
| and the follow-up contract it reads back.
*/

beforeEach(function (): void {
    $this->prompts = new PromptBuilder;

    $this->context = new LearningContext(
        userId: 1,
        educationLevel: 'middle_school',
        difficultyPreference: 'beginner',
        organName: 'Heart',
        structureName: 'Left ventricle',
        lessonTitle: 'Blood circulation',
        recentMistakes: ['Right atrium'],
    );
});

it('assembles the PRD §39 context block', function (): void {
    $messages = $this->prompts->messages(
        context: $this->context,
        task: TutorTask::Ask,
        question: 'Why is its wall thicker?',
        curatedNotes: ['Function of Left ventricle: pumps blood into the aorta.'],
    );

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['role'])->toBe('user');

    expect($messages[0]['content'])
        ->toContain('Student level:')
        ->toContain('Middle school')
        ->toContain('Current organ:')
        ->toContain('Heart')
        ->toContain('Selected structure:')
        ->toContain('Left ventricle')
        ->toContain('Current lesson:')
        ->toContain('Blood circulation')
        ->toContain('Recently confused structures:')
        ->toContain('Right atrium')
        ->toContain('pumps blood into the aorta')
        ->toContain('Student question:')
        ->toContain('Why is its wall thicker?');
});

it('tells the model to say so when there are no sources', function (): void {
    // The "no relevant sources, and saying so" path, built now so Handover 11
    // inherits it (docs/architecture.md §8.2).
    $content = $this->prompts->messages($this->context, TutorTask::Ask, 'Why?')[0]['content'];

    expect($content)
        ->toContain('Relevant educational sources:')
        ->toContain('None.')
        ->toContain('tell the student that is what you did');
});

it('formats retrieved sources with their citation once they exist', function (): void {
    $content = $this->prompts->messages(
        context: $this->context,
        task: TutorTask::Ask,
        question: 'Why?',
        sources: [new RetrievedChunk(
            id: 'chunk-1',
            content: 'The left ventricle generates systemic pressure.',
            score: 0.91,
            sourceTitle: 'Cardiac anatomy, ch. 3',
        )],
    )[0]['content'];

    expect($content)
        ->toContain('[Cardiac anatomy, ch. 3]')
        ->toContain('generates systemic pressure')
        ->not->toContain('None.');
});

it('adapts reading guidance to the education level', function (): void {
    $middle = $this->prompts->systemPrompt($this->context, TutorTask::Ask);

    $advanced = $this->prompts->systemPrompt(
        new LearningContext(userId: 1, educationLevel: 'advanced', difficultyPreference: 'advanced'),
        TutorTask::Ask,
    );

    expect($middle)->toContain('plain everyday words')
        ->and($advanced)->toContain('Terminologia')
        ->and($advanced)->not->toContain('plain everyday words');
});

it('carries every §8.4 safety rule into the system prompt', function (): void {
    $prompt = $this->prompts->systemPrompt($this->context, TutorTask::Ask);

    expect($prompt)
        ->toContain('not a doctor')
        ->toContain('Never diagnose')
        ->toContain('Stay strictly within educational anatomy')
        ->toContain('Never invent a fact')
        ->toContain('Prefer the supplied sources');
});

it('tells a hint not to give the answer away', function (): void {
    // Correctness belongs to Handover 07; a hint that resolves the question has
    // scored it.
    expect($this->prompts->systemPrompt($this->context, TutorTask::Hint))
        ->toContain('Do not state the answer');
});

it('builds an implied question for explain, which has none of its own', function (): void {
    $content = $this->prompts->messages($this->context, TutorTask::Explain, '')[0]['content'];

    expect($content)->toContain('Explain Left ventricle');
});

it('splits the follow-up block off the answer', function (): void {
    $split = $this->prompts->splitFollowUps(
        "The wall is thicker because of pressure.\n"
        .'FOLLOW-UP: What does the right ventricle do? || Where does the aorta go?'
    );

    expect($split['answer'])->toBe('The wall is thicker because of pressure.')
        ->and($split['followUps'])->toBe([
            'What does the right ventricle do?',
            'Where does the aorta go?',
        ]);
});

it('keeps the whole reply when the model ignored the format', function (): void {
    // A formatting miss must not cost the student a good answer.
    $split = $this->prompts->splitFollowUps('The wall is thicker because of pressure.');

    expect($split['answer'])->toBe('The wall is thicker because of pressure.')
        ->and($split['followUps'])->toBe([]);
});

it('caps follow-ups at three', function (): void {
    $split = $this->prompts->splitFollowUps('Answer.'."\n".'FOLLOW-UP: a || b || c || d || e');

    expect($split['followUps'])->toHaveCount(3);
});

it('passes the system prompt through chat options rather than as a message', function (): void {
    // Anthropic takes it as a top-level field, OpenAI as a message. Normalising
    // that is the provider adapter's job, not the prompt builder's.
    $options = $this->prompts->chatOptions($this->context, TutorTask::Ask);

    expect($options)->toHaveKeys(['system', 'max_tokens', 'temperature'])
        ->and($options['system'])->toContain('anatomy tutor');
});
