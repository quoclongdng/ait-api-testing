<?php

/*
 * Live cross-runtime probe for S14-06: loads the REAL web class
 * web/app/Services/Execution/JsonPathSubset.php (used by the Assertion Engine, the executor and
 * the web DSL validator) and prints, as a JSON list, whether each JSONPath read from stdin (a JSON
 * list of strings) is supported. ai-service validator.py must give the same answers.
 *
 * The web file path can be overridden with AIT_WEB_JSONPATH_SUBSET.
 * Exit code 3 means the web source is not available (e.g. inside the ai-service container).
 */

use App\Services\Execution\JsonPathSubset;

$classPath = getenv('AIT_WEB_JSONPATH_SUBSET')
    ?: __DIR__.'/../../../web/app/Services/Execution/JsonPathSubset.php';

if (! is_file($classPath)) {
    fwrite(STDERR, "web JsonPathSubset not found at {$classPath}".PHP_EOL);
    exit(3);
}

require_once $classPath;

$paths = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$answers = [];
foreach ($paths as $path) {
    $answers[] = JsonPathSubset::isSupported($path);
}

echo json_encode($answers, JSON_THROW_ON_ERROR), PHP_EOL;
