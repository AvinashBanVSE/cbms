<?php

declare(strict_types=1);

$report = simplexml_load_file(__DIR__.'/../coverage.xml');
if ($report === false || ! isset($report->project->metrics)) {
    fwrite(STDERR, "Coverage report unavailable.\n");
    exit(1);
}

$metrics = $report->project->metrics;
$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
if ($total === 0 || 100 * $covered < 90 * $total) {
    fwrite(STDERR, "Backend statement coverage is below 90%.\n");
    exit(1);
}
