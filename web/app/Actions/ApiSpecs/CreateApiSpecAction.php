<?php

namespace App\Actions\ApiSpecs;

use App\Enums\ApiSpecFormat;
use App\Enums\ApiSpecStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\ApiSpec;
use App\Models\Endpoint;
use App\Models\Project;
use App\Services\OpenApi\OpenApiParser;
use Illuminate\Support\Facades\DB;

class CreateApiSpecAction
{
    public function __construct(
        protected OpenApiParser $parser,
    ) {}

    /**
     * Create an ApiSpec, parse its content, and store normalized endpoints.
     *
     * @throws ApiException
     */
    public function execute(
        Project $project,
        string $versionLabel,
        string $rawContent,
        ?string $sourceFilename = null,
        ?ApiSpecFormat $format = null,
    ): ApiSpec {
        // A leading UTF-8 BOM (Windows editors) marks the encoding, it is not content: the spec is
        // parsed, stored and checksummed without it, so checksum = sha256(raw_content) still holds
        // and one document saved with and without a BOM is the same version (S14-06).
        $rawContent = OpenApiParser::withoutBom($rawContent);
        $checksum = OpenApiParser::checksum($rawContent);

        // Check uniqueness per project (docs/DESIGN.md §5 / db-schema.md constraint api_specs_project_checksum_unique)
        $existing = ApiSpec::where('project_id', $project->id)
            ->where('checksum', $checksum)
            ->first();

        if ($existing !== null && $existing->status === ApiSpecStatus::ParseFailed) {
            // The same content already failed to parse: say so and repeat the stored reasons
            // (details.errors, shown under the M06 flash) instead of a bare "đã tồn tại" that lets
            // the user think a usable copy exists (QA S14-06).
            throw new ApiException(
                ErrorCode::Conflict,
                "Nội dung này trùng với phiên bản \"{$existing->version_label}\" đã tải lên trước đó và không phân tích được. Hãy sửa các lỗi bên dưới rồi tải lên lại.",
                [
                    'checksum' => $checksum,
                    'existing_spec_id' => $existing->id,
                    'version_label' => $existing->version_label,
                    'existing_status' => $existing->status->value,
                    'errors' => array_values($existing->parse_errors ?? []),
                ]
            );
        }

        if ($existing !== null) {
            throw new ApiException(
                ErrorCode::Conflict,
                'Bản đặc tả OpenAPI với nội dung này đã tồn tại trong dự án.',
                [
                    'checksum' => $checksum,
                    'existing_spec_id' => $existing->id,
                    'version_label' => $existing->version_label,
                    'existing_status' => $existing->status->value,
                ]
            );
        }

        $detectedFormat = $format ?? $this->parser->detectFormat($rawContent);
        $filename = $sourceFilename ?: 'specification.'.$detectedFormat->value;

        $parsed = $this->parser->parse($rawContent, $detectedFormat);

        if (! $parsed->isValid) {
            // Persist failed spec record according to acceptance criteria
            $failedSpec = ApiSpec::create([
                'project_id' => $project->id,
                'version_label' => $versionLabel,
                'source_filename' => $filename,
                'format' => $detectedFormat,
                // Nothing was parsed: keep the version the document declares (or none), never a
                // made-up "3.0.0" (S13-03). The column is NOT NULL and the API contract requires a
                // non-empty string, so "none declared" is ApiSpec::UNKNOWN_OPENAPI_VERSION.
                'openapi_version' => $parsed->openapiVersion ?? $this->declaredOpenApiVersion($rawContent),
                'raw_content' => $rawContent,
                'normalized' => null,
                'checksum' => $checksum,
                'status' => ApiSpecStatus::ParseFailed,
                'parse_errors' => $parsed->errors,
            ]);

            throw new ApiException(
                ErrorCode::SpecParseFailed,
                'Không thể phân tích đặc tả OpenAPI.',
                [
                    'spec_id' => $failedSpec->id,
                    'errors' => $parsed->errors,
                ]
            );
        }

        return DB::transaction(function () use ($project, $versionLabel, $filename, $detectedFormat, $rawContent, $checksum, $parsed): ApiSpec {
            $apiSpec = ApiSpec::create([
                'project_id' => $project->id,
                'version_label' => $versionLabel,
                'source_filename' => $filename,
                'format' => $detectedFormat,
                'openapi_version' => $parsed->openapiVersion ?? '3.0.0',
                'raw_content' => $rawContent,
                'normalized' => $parsed->normalized,
                'checksum' => $checksum,
                'status' => ApiSpecStatus::Parsed,
                'parse_errors' => null,
            ]);

            foreach ($parsed->endpoints as $endpoint) {
                Endpoint::create([
                    'api_spec_id' => $apiSpec->id,
                    'method' => $endpoint->method,
                    'path' => $endpoint->path,
                    'operation_id' => $endpoint->operationId,
                    'summary' => $endpoint->summary,
                    'description' => $endpoint->description,
                    'parameters' => $endpoint->parameters,
                    'request_body' => $endpoint->requestBody,
                    'responses' => $endpoint->responses,
                    'security' => $endpoint->security,
                ]);
            }

            return $apiSpec;
        });
    }

    /** "openapi: 3.0.3" declared at the top level of a document that could not be parsed, or the unknown marker. */
    private function declaredOpenApiVersion(string $rawContent): string
    {
        if (preg_match('/(?:^|[{,])\s*["\']?openapi["\']?\s*:\s*["\']?(3\.[0-9]+(?:\.[0-9]+)?)["\']?/m', $rawContent, $match) === 1) {
            return $match[1];
        }

        return ApiSpec::UNKNOWN_OPENAPI_VERSION;
    }
}
