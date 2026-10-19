<?php

namespace App\Http\Requests\Concerns;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Spec uploads that PHP itself rejected (upload_max_filesize / MAX_FILE_SIZE) arrive as an
 * UploadedFile with an error code and an empty path, so hasFile() is false. These helpers let
 * the FormRequests still validate the file field and explain the 5 MB limit (S10-01 audit r2).
 */
trait DescribesSpecUploadErrors
{
    /**
     * Whether the multipart field was sent at all, including uploads PHP rejected.
     */
    protected function specFileWasSent(string $key): bool
    {
        return $this->hasFile($key) || $this->files->get($key) !== null;
    }

    /**
     * Message for Laravel's "uploaded" failure on the given file field.
     */
    protected function specUploadFailedMessage(string $key): string
    {
        $file = $this->files->get($key);

        if ($file instanceof UploadedFile
            && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return 'Kích thước tệp đặc tả không được vượt quá 5 MB.';
        }

        return 'Tải lên tệp đặc tả thất bại. Vui lòng thử lại.';
    }

    /**
     * Rule for an uploaded file with no content: its MIME type is application/x-empty, so the
     * `mimetypes` rule would wrongly say the .yaml/.json file has the wrong format (S14-06).
     */
    protected function rejectEmptySpecFile(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value instanceof UploadedFile && $value->isValid() && $value->getSize() === 0) {
                $fail('Tệp đặc tả rỗng, vui lòng chọn tệp có nội dung.');
            }
        };
    }
}
