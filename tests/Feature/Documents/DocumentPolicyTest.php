<?php

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows the owner to view, delete and retry a document', function (string $ability) {
    $document = Document::factory()->create();

    expect(Gate::forUser($document->user)->inspect($ability, $document)->allowed())->toBeTrue();
})->with(['view', 'update', 'delete', 'retry']);

it('hides other users\' documents with a 404', function (string $ability) {
    $document = Document::factory()->create();
    $intruder = User::factory()->create();

    $response = Gate::forUser($intruder)->inspect($ability, $document);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['view', 'update', 'delete', 'retry']);
