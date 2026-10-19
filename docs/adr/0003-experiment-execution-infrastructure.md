# ADR-0003: Hạ tầng chạy thực nghiệm phía Web (reset sandbox, seed, allowlist, tách job, xuất dữ liệu)

- Trạng thái: Chấp nhận (Duyệt bởi CODEOWNER (chat) ngày 2026-10-12)
- Ngày: 2026-10-11
- Liên quan: docs/DESIGN.md D07, D10, D14, §6, §7, §11; ADR-0002 §4; `docs/architecture/experiment-design.md` §4.2–§4.3; task S11-02

## Bối cảnh
Trước khi chạy thực nghiệm chính thức (S11-02), rà soát `RunExperimentAction` cho thấy:
1. Không gọi reset sandbox trước lượt chạy bản sạch và từng biến thể bug, trái ADR-0002 §4.
2. Seed lần lặp r là `config.seed + r - 1`, trái giao thức seed cố định 42, 1024, 2026 (experiment-design §4.2).
3. Host của các biến thể bug (ví dụ `simple-store-bug_001`) không thuộc allowlist mặc định nên mọi thực nghiệm thất bại với `TARGET_NOT_ALLOWED` (D07).
4. `base_url` trong `ground-truth.yaml` là địa chỉ phía máy host (`localhost:800x`), worker trong Docker không gọi được.
5. Cả thực nghiệm chạy trong một job `timeout = 3600` trong khi worker chạy `--timeout=600` và `DB_QUEUE_RETRY_AFTER = 90`: job dài bị giết hoặc bị nhả lại để chạy lần hai (vi phạm tính insert-only của `test_results`).
6. Không có cách xuất dữ liệu thô (từng lần lặp, từng bug, cấu hình model/temperature/seed) để phân tích S12-01, và `make experiment` (S12-02) không có lệnh để tạo/chờ thực nghiệm.

## Các phương án đã xem xét
1. **Allowlist theo mẫu (wildcard) trong `ALLOWED_TARGET_HOSTS`**. Ưu: không cần ghi DB. Nhược: nới rộng ngữ nghĩa D07, khó kiểm soát, không hiện trên M04.
2. **Tự thêm dòng `allowed_targets` chính xác host:port của biến thể sạch và từng biến thể bug** (suy ra từ URL template). Ưu: giữ nguyên D07 (vẫn resolve host trước khi kiểm tra, pin địa chỉ, không redirect), hiển thị và xóa được trên M04. Nhược: cần người tạo (`created_by`).
3. **Giữ một job cho cả thực nghiệm, tăng timeout**. Nhược: một thực nghiệm 3 lần lặp × (1 + 12 bug) có thể vượt mọi timeout hợp lý.
4. **Tách thực nghiệm thành chuỗi job theo bước** (sinh test của lần lặp r, chạy một biến thể, tổng hợp). Ưu: mỗi job ngắn, tiến độ lưu dần vào `repetitions_data`. Nhược: trạng thái trung gian nằm trong DB.

## Quyết định
- **Reset sandbox**: hợp đồng `BenchmarkSandbox` có thêm `reset(baseUrl)`; bản thật gửi `POST <variant>/__admin/reset` qua `TargetHttpClient` sau khi kiểm tra allowlist D07; HTTP 404 được chấp nhận kèm log cảnh báo, lỗi khác làm thực nghiệm `FAILED`. Gọi trước **mỗi** lượt chạy bản sạch và từng biến thể bug. Có `FakeBenchmarkSandbox` cho test (D08).
- **Seed**: `config.seeds` (mảng, tùy chọn khi tạo) lưu seed từng lần lặp; mặc định theo `EXPERIMENT_SEEDS = 42,1024,2026`. Nếu chỉ truyền `config.seed` khác seed đầu giao thức thì seed đó thay lần lặp 1, các lần sau theo giao thức; thiếu seed thì nối tiếp `seed cuối + 1`. `config.seed = seeds[0]`.
- **Allowlist**: chọn phương án 2 — `php artisan ait:benchmarks:import --allow-targets [--user=]` và `ait:experiments:run` tự thêm dòng `allowed_targets` (host:port chính xác).
- **Base URL bản sạch**: `BENCHMARK_CLEAN_URL_TEMPLATE` (mặc định `http://{slug}:8000`, tên service Docker của benchmark); để trống thì dùng `benchmarks.base_url`.
- **Tách job**: chọn phương án 4 — `RunExperimentJob` chỉ chuyển `PLANNED → RUNNING` và lập kế hoạch, rồi xích các `RunExperimentStepJob` (`timeout = 1500 s`). Worker `--timeout=1800`, `DB_QUEUE_RETRY_AFTER = 1900` (job timeout ≤ worker timeout < retry_after, có test bảo vệ).
- **Xuất dữ liệu**: thêm `GET /api/v1/experiments/{id}/export?format=json|csv` (TESTER, ADMIN) và `php artisan ait:experiments:export [ids] --format --output`. Thêm `ait:experiments:run` và `ait:experiments:wait` làm nền cho `make experiment` (target Makefile sẽ được thêm ở S12-02; hiện chạy trực tiếp bằng `php artisan`).
- Thứ tự endpoint gửi cho ai-service được cố định theo `id` để prompt và các lần lặp tái lập được (D09).

## Hệ quả
- Tích cực: thực nghiệm tuân thủ ADR-0002 §4 và giao thức seed; không còn job bị giết/chạy lặp; dữ liệu thô xuất được cho S12-01; đã có lệnh artisan tạo và chờ thực nghiệm (`ait:experiments:run`, `ait:experiments:wait`), còn target `make experiment` thuộc S12-02.
- Tiêu cực / rủi ro: benchmark chưa có `POST /__admin/reset` vẫn chạy (chỉ cảnh báo) nên dữ liệu có thể bẩn — cần bổ sung endpoint ở các benchmark (task phía benchmark/docker). Hợp đồng `ExperimentConfig` có thêm `seeds`; `ExperimentRepetition.clean_test_run_id` có thể `null` trong lúc thực nghiệm đang chạy.
- Việc cần làm theo: cập nhật docs/DESIGN.md §6 (export) và §7 (`BENCHMARK_CLEAN_URL_TEMPLATE`, `BENCHMARK_RESET_TIMEOUT_MS`, `EXPERIMENT_SEEDS`, `DB_QUEUE_RETRY_AFTER`), `web-api.openapi.yaml`, `db-schema.md`, `experiment-design.md`, `web/.env.example` — thực hiện trong cùng PR.
