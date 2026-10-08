<?php

declare(strict_types=1);

use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

function tiaChangeGreeter(Project $project): void
{
    $project->write('app/Greeter.php', str_replace(
        '$name);',
        'trim($name));',
        (string) file_get_contents($project->path('app/Greeter.php')),
    ));
}

function tiaChangeCalculator(Project $project): void
{
    $project->write('app/Calculator.php', str_replace(
        'return $a + $b;',
        'return $b + $a;',
        (string) file_get_contents($project->path('app/Calculator.php')),
    ));
}

test('a branch is not charged for what the default branch changed after the baseline', function (?string $overlay, int $affected, int $replayed): void {
    $project = Project::make('master', overlay: $overlay);
    $project->seed('master');

    tiaChangeGreeter($project);
    $project->git()->commit('change the greeter on master');
    $project->git()->setOriginHead('master');

    $project->git()->switchTo('feature-x', new: true);
    tiaChangeCalculator($project);
    $project->git()->commit('change the calculator on the branch');

    $result = $project->pest('--tia');

    expect($result->affected())->toBe($affected, $result->describe())
        ->and($result->replayed())->toBe($replayed, $result->describe());
})->with([
    'trusting the default branch' => ['trusted-default-branch', 4, 2],
    'stock behaviour' => ['configured-default-branch', 6, 0],
])->skipOnWindows();

test('a branch that lacks the baseline commit still replays against it', function (): void {
    $project = Project::make('master', overlay: 'trusted-default-branch');

    $project->git()->switchTo('feature-x', new: true);
    tiaChangeCalculator($project);
    $project->git()->commit('change the calculator on the branch');

    $project->git()->switchTo('master');
    tiaChangeGreeter($project);
    $project->git()->commit('change the greeter on master');
    $project->git()->setOriginHead('master');
    $project->seed('master');

    $project->git()->switchTo('feature-x');

    $result = $project->pest('--tia');

    expect($result->output)->not->toContain('no longer reachable')
        ->and($result->affected())->toBe(4, $result->describe())
        ->and($result->replayed())->toBe(2, $result->describe());
})->skipOnWindows();

test('a branch still selects a file it changed that the default branch also changed', function (): void {
    $project = Project::make('master', overlay: 'trusted-default-branch');
    $project->seed('master');

    tiaChangeGreeter($project);
    $project->git()->commit('change the greeter on master');
    $project->git()->setOriginHead('master');

    $project->git()->switchTo('feature-x', new: true);
    $project->write('app/Greeter.php', str_replace(
        'trim($name)',
        'ltrim($name)',
        (string) file_get_contents($project->path('app/Greeter.php')),
    ));

    $result = $project->pest('--tia');

    expect($result->affected())->toBe(2, $result->describe())
        ->and($result->replayed())->toBe(4, $result->describe());
})->skipOnWindows();

test('the default branch itself keeps selecting what changed since its baseline', function (): void {
    $project = Project::make('master', overlay: 'trusted-default-branch');
    $project->seed('master');

    tiaChangeGreeter($project);
    $project->git()->commit('change the greeter on master');
    $project->git()->setOriginHead('master');

    $result = $project->pest('--tia');

    expect($result->affected())->toBe(2, $result->describe())
        ->and($result->replayed())->toBe(4, $result->describe());
})->skipOnWindows();

test('merging the default branch into a branch that already ran selects nothing new', function (): void {
    $project = Project::make('master', overlay: 'trusted-default-branch');
    $project->seed('master');

    $project->git()->switchTo('feature-x', new: true);
    tiaChangeCalculator($project);
    $project->git()->commit('change the calculator on the branch');

    $first = $project->pest('--tia');

    expect($first->affected())->toBe(4, $first->describe());

    $project->git()->switchTo('master');
    tiaChangeGreeter($project);
    $project->git()->commit('change the greeter on master');
    $project->git()->setOriginHead('master');

    $project->git()->switchTo('feature-x');
    $project->git()->run(['merge', '--quiet', '--no-edit', 'master']);

    $second = $project->pest('--tia');

    expect($second->affected())->toBe(0, $second->describe())
        ->and($second->replayed())->toBe(Project::TOTAL_TESTS, $second->describe());
})->skipOnWindows();

test('without a default branch ref the selection is left as it was', function (): void {
    $project = Project::make('trunk', overlay: 'trusted-default-branch');
    $project->seed('trunk');
    $project->mutateGraph(function (array $graph): array {
        $graph['baselines']['master'] = $graph['baselines']['trunk'];
        unset($graph['baselines']['trunk']);

        return $graph;
    });

    tiaChangeGreeter($project);
    $project->git()->commit('change the greeter');

    $project->git()->switchTo('feature-x', new: true);

    $result = $project->pest('--tia');

    expect($result->affected())->toBe(2, $result->describe());
})->skipOnWindows();

function tiaSeedWithGreetingView(Project $project): void
{
    $project->write('resources/views/greeting.blade.php', "<p>Hello</p>\n");
    $project->git()->commit('add the greeting view');
    $project->git()->setOriginHead('master');
    $project->seed('master');

    $project->mutateGraph(function (array $graph): array {
        $id = count($graph['files']);
        $graph['files'][$id] = 'resources/views/greeting.blade.php';
        $graph['edges']['tests/Unit/GreeterTest.php'][] = $id;

        return $graph;
    });
}

test('a file the default branch deleted after the baseline selects nothing on a branch', function (): void {
    $project = Project::make('master', overlay: 'trusted-default-branch');
    tiaSeedWithGreetingView($project);

    $project->git()->run(['rm', '--quiet', 'resources/views/greeting.blade.php']);
    $project->git()->commit('delete the greeting view on master');
    $project->git()->setOriginHead('master');

    $project->git()->switchTo('feature-x', new: true);

    $result = $project->pest('--tia');

    expect($result->affected())->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(Project::TOTAL_TESTS, $result->describe());
})->skipOnWindows();

test('a file the branch deleted still selects the tests that used it', function (): void {
    $project = Project::make('master', overlay: 'trusted-default-branch');
    tiaSeedWithGreetingView($project);

    $project->git()->switchTo('feature-x', new: true);
    $project->git()->run(['rm', '--quiet', 'resources/views/greeting.blade.php']);
    $project->git()->commit('delete the greeting view on the branch');

    $result = $project->pest('--tia');

    expect($result->affected())->toBe(2, $result->describe())
        ->and($result->replayed())->toBe(4, $result->describe());
})->skipOnWindows();
