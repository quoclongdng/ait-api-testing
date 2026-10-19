<?php

namespace Tests\Feature;

use App\Enums\ApiSpecFormat;
use App\Enums\ApiSpecStatus;
use App\Enums\HttpMethod;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DemoProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SpecScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherTester;

    private User $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->tester()->create();
        $this->otherTester = User::factory()->tester()->create();
        $this->admin = User::factory()->admin()->create();

        $this->project = Project::factory()->create([
            'name' => 'Demo Store Project',
            'description' => 'Kiểm thử OpenAPI Store',
            'owner_id' => $this->owner->id,
            'default_target_url' => 'http://localhost:8002',
        ]);
    }

    public function test_guest_cannot_access_m06_or_m07_screens(): void
    {
        $responseM06 = $this->get("/projects/{$this->project->id}/specs");
        $responseM06->assertRedirect('/login');

        $responseM07 = $this->get("/projects/{$this->project->id}/specs/1");
        $responseM07->assertRedirect('/login');
    }

    public function test_non_owner_tester_cannot_view_m06_screen(): void
    {
        $response = $this->actingAs($this->otherTester)->get("/projects/{$this->project->id}/specs");
        $response->assertForbidden();
    }

    public function test_non_owner_tester_cannot_upload_spec(): void
    {
        $yamlContent = <<<'YAML'
openapi: 3.1.0
info:
  title: Test API
  version: 1.0.0
paths:
  /users:
    get:
      summary: List users
      responses:
        '200':
          description: OK
YAML;
        $file = UploadedFile::fake()->createWithContent('api-spec.yaml', $yamlContent);

        $response = $this->actingAs($this->otherTester)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v1.0.0',
            'input_method' => 'file',
            'spec_file' => $file,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ApiSpec::where('project_id', $this->project->id)->count());
    }

    public function test_non_owner_tester_cannot_view_m07_screen(): void
    {
        $spec = $this->project->specs()->create([
            'version_label' => 'v1.0.0',
            'source_filename' => 'store.yaml',
            'format' => ApiSpecFormat::Yaml,
            'openapi_version' => '3.1.0',
            'raw_content' => 'openapi: 3.1.0',
            'checksum' => hash('sha256', 'store.yaml'),
            'status' => ApiSpecStatus::Parsed,
        ]);

        $response = $this->actingAs($this->otherTester)->get("/projects/{$this->project->id}/specs/{$spec->id}");
        $response->assertForbidden();
    }

    public function test_admin_can_access_any_project_m06_and_m07(): void
    {
        $spec = $this->project->specs()->create([
            'version_label' => 'v1.0.0',
            'source_filename' => 'store.yaml',
            'format' => ApiSpecFormat::Yaml,
            'openapi_version' => '3.1.0',
            'raw_content' => 'openapi: 3.1.0',
            'checksum' => hash('sha256', 'store-admin.yaml'),
            'status' => ApiSpecStatus::Parsed,
        ]);

        $responseM06 = $this->actingAs($this->admin)->get("/projects/{$this->project->id}/specs");
        $responseM06->assertOk();

        $responseM07 = $this->actingAs($this->admin)->get("/projects/{$this->project->id}/specs/{$spec->id}");
        $responseM07->assertOk();
    }

    public function test_authenticated_owner_can_view_m06_screen(): void
    {
        $spec = $this->project->specs()->create([
            'version_label' => 'v1.0.0-beta',
            'source_filename' => 'store-v1.yaml',
            'format' => ApiSpecFormat::Yaml,
            'openapi_version' => '3.1.0',
            'raw_content' => 'openapi: 3.1.0',
            'checksum' => hash('sha256', 'openapi: 3.1.0'),
            'status' => ApiSpecStatus::Parsed,
        ]);

        $response = $this->actingAs($this->owner)->get("/projects/{$this->project->id}/specs");

        $response->assertOk()
            ->assertDontSee('Quản lý đặc tả OpenAPI')
            ->assertSee('Tải lên phiên bản đặc tả mới')
            ->assertSee('Lịch sử các phiên bản đặc tả')
            ->assertSee('v1.0.0-beta')
            ->assertSee('store-v1.yaml')
            ->assertSee('PARSED');
    }

    public function test_can_upload_valid_yaml_spec_file(): void
    {
        $yamlContent = <<<'YAML'
openapi: 3.1.0
info:
  title: Test API
  version: 1.0.0
paths:
  /users:
    get:
      operationId: listUsers
      summary: Retrieve all users
      responses:
        '200':
          description: Success
    post:
      operationId: createUser
      summary: Create new user
      responses:
        '201':
          description: Created
YAML;

        $file = UploadedFile::fake()->createWithContent('api-spec.yaml', $yamlContent);

        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v1.1.0',
            'input_method' => 'file',
            'spec_file' => $file,
        ]);

        $spec = ApiSpec::where('version_label', 'v1.1.0')->first();
        $this->assertNotNull($spec);
        $this->assertSame(ApiSpecStatus::Parsed, $spec->status);
        $this->assertSame(ApiSpecFormat::Yaml, $spec->format);
        $this->assertSame('3.1.0', $spec->openapi_version);
        $this->assertCount(2, $spec->endpoints);

        $response->assertRedirect("/projects/{$this->project->id}/specs/{$spec->id}");
    }

    public function test_can_upload_spec_by_pasting_json_text(): void
    {
        $jsonContent = json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Pasted API', 'version' => '1.0.0'],
            'paths' => [
                '/items/{id}' => [
                    'get' => [
                        'operationId' => 'getItem',
                        'summary' => 'Get single item',
                        'responses' => ['200' => ['description' => 'OK']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v2.0.0-json',
            'input_method' => 'text',
            'spec_text' => $jsonContent,
        ]);

        $spec = ApiSpec::where('version_label', 'v2.0.0-json')->first();
        $this->assertNotNull($spec);
        $this->assertSame(ApiSpecStatus::Parsed, $spec->status);
        $this->assertSame(ApiSpecFormat::Json, $spec->format);
        $this->assertCount(1, $spec->endpoints);

        $response->assertRedirect("/projects/{$this->project->id}/specs/{$spec->id}");
    }

    public function test_uploading_duplicate_spec_is_prevented(): void
    {
        $content = "openapi: 3.1.0\ninfo:\n  title: Duplicate Test\n  version: 1.0\npaths:\n  /test:\n    get:\n      responses:\n        '200':\n          description: ok";
        $file1 = UploadedFile::fake()->createWithContent('spec1.yaml', $content);
        $file2 = UploadedFile::fake()->createWithContent('spec2.yaml', $content);

        // 1st upload
        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v1.0',
            'input_method' => 'file',
            'spec_file' => $file1,
        ]);

        // 2nd upload (duplicate)
        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v1.1',
            'input_method' => 'file',
            'spec_file' => $file2,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(1, ApiSpec::where('project_id', $this->project->id)->count());
    }

    public function test_uploading_invalid_spec_records_parse_failed(): void
    {
        $invalidYaml = "openapi: 3.1.0\npaths: [broken yaml";
        $file = UploadedFile::fake()->createWithContent('invalid.yaml', $invalidYaml);

        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v-broken',
            'input_method' => 'file',
            'spec_file' => $file,
        ]);

        $response->assertSessionHas('error');

        $spec = ApiSpec::where('version_label', 'v-broken')->first();
        $this->assertNotNull($spec);
        $this->assertSame(ApiSpecStatus::ParseFailed, $spec->status);
        $this->assertNotNull($spec->parse_errors);
    }

    public function test_authenticated_owner_can_view_m07_screen(): void
    {
        $spec = $this->project->specs()->create([
            'version_label' => 'v1.0.0',
            'source_filename' => 'store-api.yaml',
            'format' => ApiSpecFormat::Yaml,
            'openapi_version' => '3.1.0',
            'raw_content' => 'openapi: 3.1.0',
            'checksum' => hash('sha256', 'store-api.yaml'),
            'status' => ApiSpecStatus::Parsed,
        ]);

        Endpoint::create([
            'api_spec_id' => $spec->id,
            'method' => HttpMethod::Post,
            'path' => '/api/v1/orders',
            'operation_id' => 'createOrder',
            'summary' => 'Tạo đơn đặt hàng mới',
            'description' => 'Xử lý đơn hàng của khách hàng',
            'parameters' => [
                ['name' => 'Authorization', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            'request_body' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ],
            'responses' => [
                '201' => ['description' => 'Order created'],
                '400' => ['description' => 'Bad request'],
            ],
        ]);

        $response = $this->actingAs($this->owner)->get("/projects/{$this->project->id}/specs/{$spec->id}");

        $response->assertOk()
            ->assertSee('Danh sách endpoint')
            ->assertSee('Chi tiết schema của từng endpoint')
            ->assertSee('store-api.yaml')
            ->assertSee('/api/v1/orders')
            ->assertSee('POST')
            ->assertSee('createOrder')
            ->assertSee('Tạo đơn đặt hàng mới');
    }

    public function test_m07_returns_404_when_spec_does_not_belong_to_project(): void
    {
        $otherProject = Project::factory()->create([
            'owner_id' => $this->owner->id,
        ]);

        $spec = $this->project->specs()->create([
            'version_label' => 'v1.0.0',
            'source_filename' => 'store-api.yaml',
            'format' => ApiSpecFormat::Yaml,
            'openapi_version' => '3.1.0',
            'raw_content' => 'openapi: 3.1.0',
            'checksum' => hash('sha256', 'spec-other.yaml'),
            'status' => ApiSpecStatus::Parsed,
        ]);

        // Attempt to access $spec through $otherProject
        $response = $this->actingAs($this->owner)->get("/projects/{$otherProject->id}/specs/{$spec->id}");
        $response->assertNotFound();
    }

    public function test_demo_project_seeder_does_not_hardcode_id(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->seed(DemoProjectSeeder::class);

        $demo = Project::where('name', 'Hệ thống Quản lý Bán hàng')->first();
        $this->assertNotNull($demo);
        $this->assertGreaterThan(0, $demo->id);
    }

    public function test_exception_details_are_hidden_when_app_debug_is_false(): void
    {
        config(['app.debug' => false]);

        $invalidYaml = "openapi: 3.1.0\npaths: [broken yaml";
        $file = UploadedFile::fake()->createWithContent('crash.yaml', $invalidYaml);

        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v-crash',
            'input_method' => 'file',
            'spec_file' => $file,
        ]);

        $response->assertSessionHas('error');
        $errorMsg = session('error');
        $this->assertStringNotContainsString('Exception:', (string) $errorMsg);
        $this->assertStringNotContainsString('Stack trace', (string) $errorMsg);
    }

    public function test_spec_text_exceeding_5mb_in_bytes_is_rejected(): void
    {
        // 5.400.017 bytes payload
        $largeText = str_repeat('a', 5400017);

        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v-large',
            'input_method' => 'text',
            'spec_text' => $largeText,
        ]);

        $response->assertSessionHasErrors(['spec_text']);
        $this->assertSame(0, ApiSpec::where('version_label', 'v-large')->count());
    }

    public function test_upload_rejects_txt_file_extension(): void
    {
        $file = UploadedFile::fake()->createWithContent('spec.txt', "openapi: 3.1.0\ninfo:\n  title: Txt\n  version: 1.0\npaths: {}");

        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v-txt',
            'input_method' => 'file',
            'spec_file' => $file,
        ]);

        $response->assertSessionHasErrors(['spec_file']);
        $this->assertSame(0, ApiSpec::where('version_label', 'v-txt')->count());
    }

    public function test_specs_redirect_route_denies_non_owner_without_redirect(): void
    {
        $spec = $this->project->specs()->create([
            'version_label' => 'v1.0.0',
            'source_filename' => 'store.yaml',
            'format' => ApiSpecFormat::Yaml,
            'openapi_version' => '3.1.0',
            'raw_content' => 'openapi: 3.1.0',
            'checksum' => hash('sha256', 'store-secret.yaml'),
            'status' => ApiSpecStatus::Parsed,
        ]);

        // Non-owner tester gets 403 Forbidden immediately, no redirect
        $response = $this->actingAs($this->otherTester)->get("/specs/{$spec->id}");
        $response->assertForbidden();

        // Owner gets redirected to the project specs page
        $ownerResponse = $this->actingAs($this->owner)->get("/specs/{$spec->id}");
        $ownerResponse->assertRedirect("/projects/{$this->project->id}/specs/{$spec->id}");
    }

    /**
     * Regression (S10-01 audit r2): a spec file over PHP's upload limit showed the generic
     * "Tải lên spec file thất bại." instead of the 5 MB size message.
     */
    public function test_file_rejected_by_php_upload_limit_shows_the_5mb_message(): void
    {
        // PHP leaves tmp_name empty when it rejects the upload, exactly like production.
        $file = new UploadedFile('', 'large-spec.yaml', 'application/yaml', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->actingAs($this->owner)->post("/projects/{$this->project->id}/specs", [
            'version_label' => 'v-big',
            'input_method' => 'file',
            'spec_file' => $file,
        ]);

        $response->assertSessionHasErrors(['spec_file']);
        $this->assertStringContainsString('5 MB', (string) session('errors')->first('spec_file'));
        $this->assertSame(0, ApiSpec::where('version_label', 'v-big')->count());
    }
}
