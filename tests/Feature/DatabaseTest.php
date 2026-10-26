<?php

use Illuminate\Support\Facades\DB;

it('runs against the dedicated PostgreSQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('askmydocs_test');
});

it('has the pgvector extension enabled', function () {
    $extension = DB::selectOne("select extversion from pg_extension where extname = 'vector'");

    expect($extension)->not->toBeNull();
});

it('can compute cosine distance between vectors', function () {
    $result = DB::selectOne(
        "select '[1,0,0]'::vector <=> '[1,0,0]'::vector as same, '[1,0,0]'::vector <=> '[0,1,0]'::vector as orthogonal"
    );

    expect((float) $result->same)->toBe(0.0)
        ->and((float) $result->orthogonal)->toBe(1.0);
});
