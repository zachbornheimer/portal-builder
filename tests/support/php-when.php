<?php

/**
 * CLI harness for Portal_When phrasing. No WordPress.
 */

require_once dirname(__DIR__, 2) . '/includes/Definition/class-portal-when.php';

$path = $argv[1] ?? '';
$cases = json_decode((string) file_get_contents($path), true);
if (!is_array($cases)) {
    fwrite(STDERR, "invalid cases\n");
    exit(2);
}

$out = array();
foreach ($cases as $case) {
    $publish = isset($case['publish']) && is_array($case['publish']) ? $case['publish'] : array();
    $which = isset($case['which']) ? (string) $case['which'] : 'close';
    $part = isset($case['part']) ? (string) $case['part'] : 'phrase';
    $instant = Portal_When::shown_instant($publish, $which);
    $timezone = Portal_When::timezone($publish);
    $phrase = 'date' === $part
        ? Portal_When::date_label($instant, $timezone)
        : Portal_When::phrase($instant, $timezone);
    $out[] = array(
        'name' => isset($case['name']) ? $case['name'] : '',
        'phrase' => $phrase,
        'expect' => isset($case['expect']) ? $case['expect'] : '',
    );
}

echo json_encode($out) . "\n";
