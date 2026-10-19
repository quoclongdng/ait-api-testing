<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ApiSpecs\CreateApiSpecAction;
use App\Enums\ApiSpecFormat;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiSpecs\CreateApiSpecRequest;
use App\Http\Resources\ApiSpecResource;
use App\Models\ApiSpec;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

class ApiSpecController extends Controller
{
    /**
     * Upload and parse an OpenAPI specification for a project.
     */
    public function store(CreateApiSpecRequest $request, Project $project, CreateApiSpecAction $action): JsonResponse
    {
        if (! Gate::allows('createSpec', $project)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Bạn không có quyền tải lên đặc tả cho dự án này.'
            );
        }

        if ($request->hasFile('file')) {
            /** @var UploadedFile $file */
            $file = $request->file('file');
            $rawContent = (string) file_get_contents($file->getRealPath());
            $sourceFilename = $file->getClientOriginalName();
            $ext = strtolower($file->getClientOriginalExtension());
            $format = in_array($ext, ['yaml', 'yml'], true)
                ? ApiSpecFormat::Yaml
                : ApiSpecFormat::Json;
            $versionLabel = (string) $request->input('version_label');
        } else {
            $rawContent = (string) $request->input('raw_content');
            $sourceFilename = $request->filled('source_filename')
                ? (string) $request->input('source_filename')
                : null;
            $format = ApiSpecFormat::from((string) $request->input('format'));
            $versionLabel = (string) $request->input('version_label');
        }

        $apiSpec = $action->execute(
            project: $project,
            versionLabel: $versionLabel,
            rawContent: $rawContent,
            sourceFilename: $sourceFilename,
            format: $format,
        );

        return response()->json([
            'data' => (new ApiSpecResource($apiSpec))->resolve(),
        ], 201);
    }

    /**
     * Get an OpenAPI specification by ID.
     */
    public function show(ApiSpec $spec): JsonResponse
    {
        if (! Gate::allows('view', $spec)) {
            throw new ApiException(
                ErrorCode::Forbidden,
                'Bạn không có quyền truy cập bản đặc tả này.'
            );
        }

        return response()->json([
            'data' => (new ApiSpecResource($spec))->resolve(),
        ]);
    }
}
