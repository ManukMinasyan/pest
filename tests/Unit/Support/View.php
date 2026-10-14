<?php

declare(strict_types=1);

use Pest\Support\View;
use Symfony\Component\Console\Output\BufferedOutput;

it('leaves no output buffer open after rendering', function (): void {
    $output = new BufferedOutput;
    View::renderUsing($output);
    $level = ob_get_level();

    View::render('components.badge', ['type' => 'INFO', 'content' => 'rendered']);

    expect(ob_get_level())->toBe($level)
        ->and($output->fetch())->toContain('rendered');
});
