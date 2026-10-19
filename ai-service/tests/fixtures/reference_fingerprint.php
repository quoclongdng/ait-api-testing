<?php

/*
 * Live cross-runtime probe for S6-02 (S10-01 audit r2): loads the REAL web class
 * web/app/Services/TestEngine/TestFingerprintService.php instead of a hand-copied
 * duplicate, so any change to the PHP canonicalisation is caught by the Python suite.
 *
 * The web file path can be overridden with AIT_WEB_FINGERPRINT_SERVICE.
 * Exit code 3 means the web source is not available (e.g. inside the ai-service container).
 */

namespace App\Models {
    if (! class_exists(TestCase::class, false)) {
        // Minimal stand-in for the Eloquent model referenced in the service signature.
        class TestCase {}
    }
}

namespace {

use App\Services\TestEngine\TestFingerprintService;

$servicePath = getenv('AIT_WEB_FINGERPRINT_SERVICE')
    ?: __DIR__.'/../../../web/app/Services/TestEngine/TestFingerprintService.php';

if (! is_file($servicePath)) {
    fwrite(STDERR, "web TestFingerprintService not found at {$servicePath}".PHP_EOL);
    exit(3);
}

require_once $servicePath;

$f = new TestFingerprintService();
$inputs = array_slice($argv, 1);
if ($inputs === []) {
    $inputs = [
        '{"n":9223372036854775807}',
        '{"n":9223372036854775808}',
        '{"n":-9223372036854775808}',
        '{"n":-9223372036854775809}',
        '{"n":10000000000000000000}',
        '{"n":1e19}',
        '{"n":100000000000000000000}',
        '{"n":1e20}',
        '{"n":1000000000000000000000}',
        '{"n":1e21}',
        '{"n":-1000000000000000000000}',
        '{"n":-1e21}',
    ];
}

foreach ($inputs as $raw) {
    echo 'PHP raw='.$raw.' canonical='.$f->toCanonicalJson($raw).' sha='.$f->calculate($raw).PHP_EOL;
}

}
