<?php

namespace Tests\Feature\Api;

use App\Enums\ApiSpecFormat;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Enums\HttpMethod;
use App\Enums\UserRole;
use App\Models\ApiSpec;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ApiSpecTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $filename): string
    {
        $path = dirname(__DIR__, 2).'/Fixtures/OpenApi/'.$filename;

        return (string) file_get_contents($path);
    }

    public function test_guest_cannot_upload_spec(): void
    {
        $project = Project::factory()->create();

        $response = $this->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1.0',
            'format' => 'json',
            'raw_content' => '{}',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', ErrorCode::Unauthenticated->value);
    }

    public function test_tester_cannot_upload_spec_to_another_users_project(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Tester]);
        $otherTester = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $response = $this->actingAs($otherTester)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1.0',
            'format' => 'json',
            'raw_content' => $this->fixture('petstore-3.0.json'),
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', ErrorCode::Forbidden->value);
    }

    public function test_user_can_upload_valid_openapi_3_0_json_content(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $json = $this->fixture('petstore-3.0.json');

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1.0.0',
            'source_filename' => 'petstore.json',
            'format' => 'json',
            'raw_content' => $json,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.project_id', $project->id);
        $response->assertJsonPath('data.version_label', 'v1.0.0');
        $response->assertJsonPath('data.format', 'json');
        $response->assertJsonPath('data.openapi_version', '3.0.3');
        $response->assertJsonPath('data.status', ApiSpecStatus::Parsed->value);
        $response->assertJsonPath('data.parse_errors', null);

        $specId = (int) $response->json('data.id');
        $this->assertDatabaseHas('api_specs', [
            'id' => $specId,
            'status' => ApiSpecStatus::Parsed->value,
            'version_label' => 'v1.0.0',
        ]);

        // 4 endpoints: /pets (GET, POST), /pets/{id} (GET, DELETE)
        $this->assertDatabaseCount('endpoints', 4);
        $this->assertDatabaseHas('endpoints', [
            'api_spec_id' => $specId,
            'path' => '/pets',
            'method' => HttpMethod::Get->value,
            'operation_id' => 'listPets',
        ]);
        $this->assertDatabaseHas('endpoints', [
            'api_spec_id' => $specId,
            'path' => '/pets',
            'method' => HttpMethod::Post->value,
            'operation_id' => 'createPet',
        ]);
    }

    public function test_user_can_upload_valid_openapi_3_1_yaml_file(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $yaml = $this->fixture('simple-store-3.1.yaml');

        $file = UploadedFile::fake()->createWithContent('store.yaml', $yaml);

        $response = $this->actingAs($user)->post("/api/v1/projects/{$project->id}/specs", [
            'version_label' => '2026.1',
            'file' => $file,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.format', ApiSpecFormat::Yaml->value);
        $response->assertJsonPath('data.openapi_version', '3.1.0');
        $response->assertJsonPath('data.status', ApiSpecStatus::Parsed->value);

        $specId = (int) $response->json('data.id');
        $this->assertDatabaseCount('endpoints', 3);
        $this->assertDatabaseHas('endpoints', [
            'api_spec_id' => $specId,
            'path' => '/products/{productId}',
            'method' => HttpMethod::Put->value,
            'operation_id' => 'updateProduct',
        ]);
    }

    public function test_uploading_invalid_spec_saves_parse_failed_status_and_returns_422_envelope(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $brokenYaml = $this->fixture('invalid-syntax.yaml');

        $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'broken-v1',
            'format' => 'yaml',
            'raw_content' => $brokenYaml,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ErrorCode::SpecParseFailed->value);
        $this->assertNotEmpty($response->json('error.details.errors'));

        // Spec record must be saved in database with PARSE_FAILED according to DoD & acceptance criteria
        $this->assertDatabaseHas('api_specs', [
            'project_id' => $project->id,
            'version_label' => 'broken-v1',
            'status' => ApiSpecStatus::ParseFailed->value,
        ]);

        // Endpoints must NOT be created
        $this->assertDatabaseCount('endpoints', 0);
    }

    /**
     * Regression (S13-03): a failed spec that declares no OpenAPI 3.x version (e.g. "swagger: 2.0")
     * stored openapi_version '' while web-api.openapi.yaml requires a string of length 1-20.
     */
    public function test_failed_spec_without_declared_version_keeps_contract_shape(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $cases = [
            'broken-swagger' => ['json', '{"swagger": "2.0", "info": {'],
            'no-key' => ['yaml', "info:\n  title: X\n"],
        ];
        foreach ($cases as $label => [$format, $content]) {
            $upload = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/specs", [
                'version_label' => $label,
                'format' => $format,
                'raw_content' => $content,
            ]);
            $upload->assertStatus(422)->assertJsonPath('error.code', ErrorCode::SpecParseFailed->value);
            $specId = $upload->json('error.details.spec_id');
            $this->assertIsInt($specId);

            $show = $this->actingAs($user)->getJson("/api/v1/specs/{$specId}")->assertOk();
            $show->assertJsonPath('data.status', ApiSpecStatus::ParseFailed->value);
            $version = $show->json('data.openapi_version');
            $this->assertIsString($version);
            $this->assertSame(ApiSpec::UNKNOWN_OPENAPI_VERSION, $version);
            $this->assertGreaterThanOrEqual(1, strlen($version));
            $this->assertLessThanOrEqual(20, strlen($version));
        }
    }

    public function test_duplicate_spec_checksum_in_same_project_returns_409_conflict(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $json = $this->fixture('petstore-3.0.json');

        // First upload succeeds
        $first = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1.0.0',
            'format' => 'json',
            'raw_content' => $json,
        ]);
        $first->assertStatus(201);

        // Second upload with same content returns 409 CONFLICT
        $second = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1.0.1',
            'format' => 'json',
            'raw_content' => $json,
        ]);

        $second->assertStatus(409);
        $second->assertJsonPath('error.code', ErrorCode::Conflict->value);
    }

    public function test_user_can_get_spec_details(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $spec = ApiSpec::factory()->create([
            'project_id' => $project->id,
            'version_label' => 'v2.0.0',
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/specs/{$spec->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $spec->id);
        $response->assertJsonPath('data.version_label', 'v2.0.0');
    }

    public function test_user_can_list_spec_endpoints_with_pagination(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $json = $this->fixture('petstore-3.0.json');

        $upload = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1.0.0',
            'format' => 'json',
            'raw_content' => $json,
        ]);
        $upload->assertStatus(201);
        $specId = (int) $upload->json('data.id');

        $response = $this->actingAs($user)->getJson("/api/v1/specs/{$specId}/endpoints?per_page=2&page=1");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $response->assertJsonPath('meta.current_page', 1);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 4);
    }

    /**
     * Regression (QA S13-03): JsonResource::filter() re-indexed `responses` ({"200": ..., "422": ...})
     * into a JSON list, losing the status codes (contract Endpoint.responses is JsonObject).
     */
    public function test_endpoint_responses_keep_their_status_code_keys(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $yaml = <<<'YAML'
openapi: 3.0.3
info: { title: QA, version: 1.0.0 }
paths:
  /items:
    post:
      requestBody:
        required: true
        content:
          application/json:
            schema: { type: object, properties: { name: { type: string } } }
      responses:
        '201': { description: Created }
        '409': { description: Duplicate }
        '422': { description: Validation error }
YAML;

        $upload = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'v1',
            'format' => 'yaml',
            'raw_content' => $yaml,
        ])->assertStatus(201);
        $specId = (int) $upload->json('data.id');

        $response = $this->actingAs($user)->getJson("/api/v1/specs/{$specId}/endpoints")->assertOk();

        $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $responses = $body->data[0]->responses;
        $this->assertInstanceOf(\stdClass::class, $responses);
        $this->assertSame(['201', '409', '422'], array_map('strval', array_keys(get_object_vars($responses))));
        $this->assertSame('Duplicate', $responses->{'409'}->description);
        $this->assertInstanceOf(\stdClass::class, $body->data[0]->request_body);
    }

    public function test_listing_endpoints_of_parse_failed_spec_returns_422_spec_parse_failed(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $spec = ApiSpec::factory()->create([
            'project_id' => $project->id,
            'status' => ApiSpecStatus::ParseFailed,
            'parse_errors' => ['Syntax error in document'],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/specs/{$spec->id}/endpoints");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ErrorCode::SpecParseFailed->value);
    }

    public function test_admin_can_upload_and_view_specs_for_any_project(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $otherUser = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $otherUser->id]);
        $json = $this->fixture('petstore-3.0.json');

        $response = $this->actingAs($admin)->postJson("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'admin-v1',
            'format' => 'json',
            'raw_content' => $json,
        ]);

        $response->assertStatus(201);
        $specId = (int) $response->json('data.id');

        $view = $this->actingAs($admin)->getJson("/api/v1/specs/{$specId}");
        $view->assertStatus(200);
    }

    public function test_user_can_upload_valid_openapi_yaml_using_real_file_fixture(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $realPath = dirname(__DIR__, 2).'/Fixtures/OpenApi/simple-store-3.1.yaml';
        $this->assertFileExists($realPath);

        // Real fixture on disk has MIME type text/plain according to PHP fileinfo
        $file = new UploadedFile(
            path: $realPath,
            originalName: 'simple-store-3.1.yaml',
            mimeType: null,
            error: null,
            test: true
        );

        $response = $this->actingAs($user)->post("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'real-yaml-2026.1',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $response->assertJsonPath('data.format', ApiSpecFormat::Yaml->value);
        $response->assertJsonPath('data.openapi_version', '3.1.0');
        $response->assertJsonPath('data.status', ApiSpecStatus::Parsed->value);
        $response->assertJsonPath('data.source_filename', 'simple-store-3.1.yaml');

        $specId = (int) $response->json('data.id');
        $this->assertDatabaseCount('endpoints', 3);
        $this->assertDatabaseHas('endpoints', [
            'api_spec_id' => $specId,
            'path' => '/products/{productId}',
            'method' => HttpMethod::Put->value,
            'operation_id' => 'updateProduct',
        ]);
    }

    public function test_uploading_spec_txt_with_valid_json_content_returns_validation_error_422(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);
        $json = $this->fixture('petstore-3.0.json');

        // File has valid JSON content and JSON MIME but forbidden .txt extension
        $tempPath = tempnam(sys_get_temp_dir(), 'spec_test_');
        $this->assertIsString($tempPath);
        file_put_contents($tempPath, $json);

        $file = new UploadedFile(
            path: $tempPath,
            originalName: 'spec.txt',
            mimeType: 'application/json',
            error: null,
            test: true
        );

        $response = $this->actingAs($user)->post("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'txt-json-v1',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
        $this->assertArrayHasKey('file', (array) $response->json('error.details'));
    }

    public function test_uploading_txt_file_returns_validation_error(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        $file = UploadedFile::fake()->create('spec.txt', 10);

        $response = $this->actingAs($user)->post("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'txt-v1',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
        $this->assertArrayHasKey('file', (array) $response->json('error.details'));
    }

    /**
     * Regression (S10-01 audit r2): a file rejected by PHP's upload_max_filesize used to fall
     * through to the raw_content rules, reporting "format/raw_content là bắt buộc".
     */
    public function test_file_rejected_by_php_upload_limit_reports_a_size_error_on_file(): void
    {
        $user = User::factory()->create(['role' => UserRole::Tester]);
        $project = Project::factory()->create(['owner_id' => $user->id]);

        // PHP leaves tmp_name empty when it rejects the upload, exactly like production.
        $file = new UploadedFile('', 'large-spec.json', 'application/json', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->actingAs($user)->post("/api/v1/projects/{$project->id}/specs", [
            'version_label' => 'big-v1',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ErrorCode::ValidationFailed->value);
        $details = (array) $response->json('error.details');
        $this->assertArrayHasKey('file', $details);
        $this->assertArrayNotHasKey('raw_content', $details);
        $this->assertArrayNotHasKey('format', $details);
        $this->assertStringContainsString('5 MB', (string) $response->json('error.details.file.0'));
    }
}
