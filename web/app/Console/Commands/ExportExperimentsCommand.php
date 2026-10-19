<?php

namespace App\Console\Commands;

use App\Actions\Experiments\ExportExperimentBundleAction;
use App\Actions\Experiments\ExportExperimentsAction;
use App\Enums\ReportFormat;
use Illuminate\Console\Command;

/**
 * php artisan ait:experiments:export [id...] --format=json|csv [--output=path]
 * php artisan ait:experiments:export [id...] --dir=path [--summary]
 *
 * Writes the raw experiment data (config incl. model/temperature/seeds, per-repetition data,
 * per-bug detections, metrics) to a file, or to stdout when --output is omitted (S11-02).
 * With --dir it writes the whole result folder of `make experiment` instead (S12-02): one
 * JSON + CSV per experiment, all experiments in one JSON + CSV, and summary.csv / summary.md
 * (§11 metrics mean ± std per benchmark × strategy); --summary also prints summary.md.
 */
class ExportExperimentsCommand extends Command
{
    protected $signature = 'ait:experiments:export
        {ids?* : ID thực nghiệm (có thể phân cách bằng dấu phẩy); mặc định: tất cả}
        {--format=json : json | csv}
        {--output= : Đường dẫn tệp kết quả (mặc định: in ra stdout)}
        {--dir= : Thư mục kết quả: JSON + CSV từng thực nghiệm, experiments.json/.csv, summary.csv, summary.md}
        {--summary : Cùng --dir: in bảng tóm tắt summary.md ra màn hình}';

    protected $description = 'Xuất dữ liệu thô và chỉ số của thực nghiệm ra JSON/CSV (S11-02)';

    public function handle(ExportExperimentsAction $action, ExportExperimentBundleAction $bundle): int
    {
        $ids = $this->ids();
        if ($ids === false) {
            return self::FAILURE;
        }

        $dir = $this->option('dir');
        if (is_string($dir) && $dir !== '') {
            return $this->exportBundle($bundle, $ids, $dir);
        }

        $format = ReportFormat::tryFrom(strtolower((string) $this->option('format')));
        if (! in_array($format, [ReportFormat::Json, ReportFormat::Csv], true)) {
            $this->error('Định dạng xuất không hợp lệ. Chỉ hỗ trợ json hoặc csv.');

            return self::FAILURE;
        }

        $file = $action->many($ids, $format);

        $output = $this->option('output');
        if (! is_string($output) || $output === '') {
            $this->output->write($file->content);

            return self::SUCCESS;
        }

        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error("Không tạo được thư mục {$directory}.");

            return self::FAILURE;
        }
        if (file_put_contents($output, $file->content) === false) {
            $this->error("Không ghi được tệp {$output}.");

            return self::FAILURE;
        }
        $this->info("Đã xuất {$file->filename} vào {$output}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<int>|null  $ids
     */
    private function exportBundle(ExportExperimentBundleAction $bundle, ?array $ids, string $dir): int
    {
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Không tạo được thư mục {$dir}.");

            return self::FAILURE;
        }

        $files = $bundle->execute($ids);
        $summary = null;
        foreach ($files as $file) {
            $path = rtrim($dir, '/').'/'.$file->filename;
            if (file_put_contents($path, $file->content) === false) {
                $this->error("Không ghi được tệp {$path}.");

                return self::FAILURE;
            }
            if ($file->filename === 'summary.md') {
                $summary = $file->content;
            }
        }

        $experiments = intdiv(max(0, count($files) - 4), 2);
        $this->info('Đã xuất '.count($files)." tệp ({$experiments} thực nghiệm) vào {$dir}.");
        if ((bool) $this->option('summary') && $summary !== null) {
            $this->newLine();
            $this->output->write($summary);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>|false|null null = every experiment; false = invalid argument (error printed)
     */
    private function ids(): array|false|null
    {
        $ids = [];
        foreach ((array) $this->argument('ids') as $arg) {
            foreach (explode(',', $arg) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^[1-9][0-9]*$/', $part) !== 1) {
                    $this->error("ID thực nghiệm không hợp lệ: {$part}");

                    return false;
                }
                $ids[] = (int) $part;
            }
        }

        return $ids === [] ? null : array_values(array_unique($ids));
    }
}
