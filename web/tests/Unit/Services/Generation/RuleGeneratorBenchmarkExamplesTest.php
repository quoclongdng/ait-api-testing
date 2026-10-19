<?php

namespace Tests\Unit\Services\Generation;

use App\Services\Benchmark\GroundTruthLoader;
use App\Services\Generation\RuleGenerator;
use App\Services\OpenApi\OpenApiParser;
use PHPUnit\Framework\TestCase;

/**
 * S11-01: the benchmark specs declare `examples` that match the seeded data, and RULE really builds
 * its valid requests from them (path ids that exist, alice's cart, quantities within stock, dates
 * matching the pattern), so the valid cases stop failing on the clean variant for unknown seed data.
 */
class RuleGeneratorBenchmarkExamplesTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>> "METHOD path" => HAPPY_PATH request of that operation
     */
    private function happyRequests(string $slug): array
    {
        $loader = new GroundTruthLoader(dirname(__DIR__, 5).'/benchmark');
        $parsed = (new OpenApiParser)->parse((string) $loader->openApiContent($slug));
        $this->assertTrue($parsed->isValid, implode('; ', $parsed->errors));
        $schemas = $parsed->normalized['components']['schemas'] ?? [];
        $auth = $loader->load($slug)->auth;

        $requests = [];
        $generator = new RuleGenerator;
        foreach ($parsed->endpoints as $endpoint) {
            foreach ($generator->generate($endpoint, ['HAPPY_PATH'], $schemas, $auth) as $case) {
                if ($case['technique'] === 'HAPPY_PATH') {
                    $request = $case['steps'][0]['request'];
                    $requests["{$request['method']} {$request['path']}"] = $request;
                }
            }
        }

        return $requests;
    }

    public function test_ecommerce_order_happy_paths_use_the_seeded_examples(): void
    {
        $requests = $this->happyRequests('ecommerce-order');

        $this->assertSame(['product_id' => 2], $requests['GET /products/{product_id}']['path_params']);
        $this->assertSame(['order_id' => 902], $requests['GET /orders/{order_id}']['path_params']);
        $this->assertSame(['product_id' => 2, 'quantity' => 1], $requests['POST /cart/items']['body']);
        $this->assertSame(['cart_id' => 101], $requests['POST /checkout']['body']);
        $this->assertSame(['items' => [['product_id' => 2, 'quantity' => 1]]], $requests['POST /orders']['body']);
        // POST /coupons/apply looks a coupon up (200, no 201): the declared `code` example wins over
        // the unique-field heuristic (S11-01 review of PR #126).
        $this->assertSame(['cart_id' => 101, 'code' => 'SAVE10'], $requests['POST /coupons/apply']['body']);
    }

    public function test_appointment_booking_gets_valid_dates_from_the_examples(): void
    {
        $requests = $this->happyRequests('appointment-booking');

        $this->assertSame(['doctor_id' => 101], $requests['GET /doctors/{doctor_id}']['path_params']);
        $this->assertSame(['date' => '2026-10-15'], $requests['GET /doctors/{doctor_id}/slots']['query']);
        // Without a date example no value matched the date pattern, so these operations had no valid case.
        $body = $requests['POST /appointments']['body'];
        $this->assertSame([102, '2026-10-16', '+84901234567'], [$body['doctor_id'], $body['appointment_date'], $body['patient_phone']]);
        $this->assertSame(['appointment_id' => 501], $requests['PATCH /appointments/{appointment_id}']['path_params']);
        $this->assertSame('2026-10-19', $requests['PATCH /appointments/{appointment_id}']['body']['new_date']);
        $this->assertSame(['appointment_id' => 501], $requests['DELETE /appointments/{appointment_id}']['path_params']);
    }

    public function test_simple_store_happy_paths_use_the_seeded_examples(): void
    {
        $requests = $this->happyRequests('simple-store');

        $this->assertSame(['id' => 1], $requests['GET /categories/{id}']['path_params']);
        $this->assertSame(['id' => 1], $requests['GET /products/{id}']['path_params']);
        $body = $requests['POST /products']['body'];
        $this->assertSame([19.99, 1, 10], [$body['price'], $body['category_id'], $body['quantity']]);
        // Create operations keep fresh values for unique-looking fields (the examples already exist).
        $this->assertNotContains($body['sku'], ['PROD-001', 'TECH_100']);
        $this->assertNotContains($requests['POST /categories']['body']['slug'], ['home-appliances', 'garden-tools']);
    }
}
