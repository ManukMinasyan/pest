<?php

declare(strict_types=1);

use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

test('replays a stored graph when tia is enabled by configuration', function (): void {
    $project = Project::make('master', overlay: 'replay-only');
    $project->seed('master');

    $result = $project->pest();

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(Project::TOTAL_TESTS, $result->describe());
})->skipOnWindows();

test('runs the suite without recording when no baseline is published', function (): void {
    $project = Project::make('master', overlay: 'replay-only');
    $environment = $project->gh('no-runs');

    $result = $project->pestWithEnvironment($project->path(), $environment);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('TIA cannot replay here')
        ->and($result->output)->not->toContain('fresh graph')
        ->and($result->tally())->toContain(Project::TOTAL_TESTS.' passed')
        ->and($project->graph()['edges'] ?? [])->toBe([], $result->describe());
})->skipOnWindows();

test('lists every test when it cannot replay', function (): void {
    $project = Project::make('master', overlay: 'replay-only');
    $environment = $project->gh('no-runs');

    $result = $project->pestWithEnvironment($project->path(), $environment, '--list-tests');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and(substr_count($result->output, '__pest_evaluable_'))->toBe(Project::TOTAL_TESTS, $result->describe());
})->skipOnWindows();

test('runs the suite instead of stopping when the baseline cannot be fetched', function (string $mode): void {
    $project = Project::make('master', overlay: 'replay-only');
    $environment = $project->gh($mode);

    $result = $project->pestWithEnvironment($project->path(), $environment);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('TIA cannot replay here')
        ->and($result->tally())->toContain(Project::TOTAL_TESTS.' passed');
})->with([
    'gh is not logged in' => ['unauthenticated'],
    'the workflow is missing' => ['list-404'],
])->skipOnWindows();

test('runs the suite without recording when the lock file no longer matches the graph', function (): void {
    $project = Project::make('master', overlay: 'replay-only');
    $project->seed('master');
    $project->write('composer.lock', '{"packages": [], "packages-dev": []}');

    $environment = $project->gh('no-runs');
    $result = $project->pestWithEnvironment($project->path(), $environment);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('TIA cannot replay here')
        ->and($result->output)->not->toContain('fresh graph')
        ->and($result->tally())->toContain(Project::TOTAL_TESTS.' passed');
})->skipOnWindows();

test('runs the suite without recording when the baseline commit is not in the clone', function (): void {
    $project = Project::make('master', overlay: 'replay-only');
    $project->seed('master');
    $project->mutateGraph(function (array $graph): array {
        $graph['baselines']['master']['sha'] = str_repeat('1', 40);

        return $graph;
    });

    $environment = $project->gh('no-runs');
    $result = $project->pestWithEnvironment($project->path(), $environment);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('TIA cannot replay here')
        ->and($result->output)->not->toContain('fresh graph')
        ->and($result->tally())->toContain(Project::TOTAL_TESTS.' passed');
})->skipOnWindows();

test('still records when --tia is passed explicitly', function (): void {
    $project = Project::make('master', overlay: 'replay-only');
    $environment = $project->gh('no-runs');

    $result = $project->pestWithEnvironment($project->path(), $environment, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->not->toContain('TIA cannot replay here')
        ->and($project->graph()['edges'] ?? [])->not->toBe([], $result->describe());
})->skipOnWindows();

test('a run with an option tia refuses goes ahead without it', function (): void {
    $project = Project::make('master', overlay: 'replay-only');
    $project->seed('master');

    $result = $project->pest('--order-by=random', '--random-order-seed=1');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->not->toContain('cannot be combined')
        ->and($result->tally())->toContain(Project::TOTAL_TESTS.' passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();
