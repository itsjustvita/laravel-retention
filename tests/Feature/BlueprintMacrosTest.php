<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

afterEach(fn () => Schema::dropIfExists('macro_test'));

it('adds nullable, indexed retention columns', function (string $macro, string $column) {
    Schema::create('macro_test', function (Blueprint $table) use ($macro) {
        $table->id();
        $table->{$macro}();
    });

    $definition = collect(Schema::getColumns('macro_test'))->firstWhere('name', $column);
    $indexed = collect(Schema::getIndexes('macro_test'))->contains(fn (array $index) => $index['columns'] === [$column]);

    expect($definition)->not->toBeNull()
        ->and($definition['nullable'])->toBeTrue()
        ->and($definition['type_name'])->toBeIn(['datetime', 'timestamp'])
        ->and($indexed)->toBeTrue();
})->with([
    'restrictable' => ['restrictable', 'restricted_at'],
    'anonymizable' => ['anonymizable', 'anonymized_at'],
]);

it('drops the retention columns again', function (string $macro, string $drop, string $column) {
    Schema::create('macro_test', function (Blueprint $table) use ($macro) {
        $table->id();
        $table->{$macro}();
    });

    Schema::table('macro_test', fn (Blueprint $table) => $table->{$drop}());

    expect(Schema::hasColumn('macro_test', $column))->toBeFalse();
})->with([
    'restrictable' => ['restrictable', 'dropRestrictable', 'restricted_at'],
    'anonymizable' => ['anonymizable', 'dropAnonymizable', 'anonymized_at'],
]);
