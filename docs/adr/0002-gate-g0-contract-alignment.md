# ADR-0002: Đồng bộ Hợp đồng và Thiết kế Hệ thống tại Gate G0 (Design Freeze)

- Trạng thái: Chấp nhận
- Ngày: 2026-10-07
- Liên quan: Gate G0, docs/DESIGN.md §2 (D04, D05, D14), §4, §5, §6, §11, tasks S0-02 -> S0-06, S1-01

## Bối cảnh
Trước khi đóng băng thiết kế (Gate G0), quá trình rà soát chéo giữa các tài liệu hợp đồng và kiến trúc phát hiện một số điểm chưa đồng bộ:
1. **Biểu diễn DSL lỗi ở Web API**: Response `TestCase` trên Web API tham chiếu cứng schema DSL hợp lệ, khiến các test case có trạng thái `INVALID` (ví dụ do LLM sinh sai cú pháp chưa repair thành công) không thể trả về trên API để phục vụ màn hình M10 (xem lỗi và sửa tay).
2. **Tính Nullable của Fingerprint**: `ai-service.openapi.yaml` cho phép `fingerprint` là `null` khi DSL hỏng nghiêm trọng chưa thể chuẩn hóa, nhưng `db-schema.md`, ERD và Web API lại bắt buộc NOT NULL / chuỗi SHA-256 64 ký tự.
3. **Liên kết dữ liệu thực nghiệm theo từng lần lặp**: `experiments` và `experiment_bug_detections` chưa có cơ chế khóa ngoại và cấu trúc dữ liệu tường minh để truy ngược trọn vẹn lần lặp $r$ sang `generation_job_id`, `test_suite_id`, `clean_test_run_id`, và từng `bug_test_run_id`.
4. **Cô lập và Reset Sandbox thực nghiệm**: Mục 4.3 trong `experiment-design.md` chưa đặc tả quy trình nạp seed ban đầu, điểm kích hoạt reset sandbox trước mỗi lượt chạy, và nguyên tắc cô lập đơn lỗi (single-fault isolation) để tránh sai lệch dữ liệu do các ca test CRUD/chuỗi làm biến đổi database sandbox.
5. **Schema DSL chấp nhận thao tác thiếu trường**: JSON Schema `test-dsl.schema.json` chưa ràng buộc `value` bắt buộc khi assertion `json_path` / `header` dùng toán tử so sánh (`eq`, `ne`, ...), và chưa bắt buộc `path` khi `extract` từ `body`/`header`. Các toán tử `exists`/`not_exists` vẫn cần cho phép không có `value`.
6. **Mô tả tiêu chí phát hiện bug lệch D14**: Một số sơ đồ trong `overview.md` và `experiment-design.md` chỉ ghi điều kiện `FAIL` trên biến thể bug, thiếu trường hợp `ERROR` (timeout/lỗi kết nối) được quy định rõ tại docs/DESIGN.md D14.
7. **Lệch pha Wireframe với Backend State Machine/API**: Wireframe M11 có nút "Hủy bỏ Job" khi đang RUNNING trong khi state machine §4.1 chỉ cho hủy khi QUEUED; Wireframe M04 có nút "Lưu cấu hình LLM" trong khi tham số hệ thống được quản lý qua biến môi trường (.env) và ghi đè theo từng job/experiment.

## Các quyết định (Decisions)

1. **Cho phép DSL mở trong TestCase Response**: Sửa schema `TestCase.dsl` và `TestCasePatch.dsl` trong `web-api.openapi.yaml` thành `type: object, additionalProperties: true` kèm mô tả rõ ràng. Ràng buộc DSL nghiêm ngặt được thực thi tự động qua `DslValidator` (PHP) trước khi chuyển trạng thái sang `VALID`/`APPROVED` và trước khi đưa vào `TestRun`.
2. **Đồng bộ Fingerprint Nullable**:
   - Thống nhất `fingerprint` mang giá trị `NULL` khi và chỉ khi test case ở trạng thái `INVALID` và payload không thể chuẩn hóa.
   - Đồng bộ cột `fingerprint` thành nullable ở `db-schema.md`, `erd.md`, migration `2026_10_06_100008_create_test_cases_table.php`, Eloquent model `TestCase`, và `web-api.openapi.yaml`.
3. **Đặc tả toàn vẹn liên kết thực nghiệm (Repetition Traceability)**:
   - Thêm cột `repetitions_data` (`jsonb`, nullable) vào bảng `experiments` lưu mảng cấu trúc JSON theo từng lần lặp: `[{ repetition, seed, generation_job_id, test_suite_id, clean_test_run_id, bug_runs }]`.
   - Bổ sung hai khóa ngoại `clean_test_run_id` và `bug_test_run_id` (FK tới `test_runs(id) ON DELETE CASCADE`) vào bảng `experiment_bug_detections` kèm index tương ứng.
   - Bổ sung schema `ExperimentRepetition` và `ExperimentBugDetection` vào `web-api.openapi.yaml`, đồng bộ ERD và tài liệu thiết kế thực nghiệm.
4. **Quy định Giao thức Cô lập và Reset Sandbox**:
   - Quy định dữ liệu seed ban đầu cố định cho mọi benchmark API.
   - Bắt buộc gọi Reset Sandbox về trạng thái seed ban đầu tại 3 thời điểm: (1) Trước mỗi lần lặp, (2) Trước lượt chạy bản sạch (Baseline Clean Run), (3) Trước mỗi lượt chạy đánh giá từng biến thể bug.
   - Tuân thủ nguyên tắc đơn lỗi: Tại một thời điểm đánh giá mutant $B_i$, chỉ bật duy nhất toggle của bug đó, tắt toàn bộ các toggle khác; sau khi chạy xong thì tắt và reset trước khi đánh giá bug kế tiếp.
5. **Thắt chặt điều kiện trong Test DSL Schema v1.0**:
   - Thêm `allOf` với `if/then` vào `$defs/extract`: Bắt buộc trường `path` khi `from` là `body` hoặc `header`.
   - Thêm `allOf` với `if/then` vào `$defs/assertion`: Bắt buộc trường `value` cho `json_path` khi `op` là toán tử giá trị (`eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`, `matches`, `type`), và cho `header` khi `op` là (`eq`, `contains`, `matches`). Cho phép bỏ qua `value` khi `op` là `exists` hoặc `not_exists`.
   - Bổ sung 3 fixture kiểm thử vi phạm và 1 fixture hợp lệ kiểm tra toán tử `exists` không cần `value` vào `docs/contracts/examples/`.
6. **Thống nhất tuyệt đối theo Tiêu chí D14**: Cập nhật toàn bộ tài liệu kiến trúc (`overview.md:54`, `experiment-design.md:171`) ghi nhận bug phát hiện khi có $\ge 1$ test PASS trên Clean và FAIL hoặc ERROR trên Bug variant.
7. **Khớp nối Wireframe với State Machine và API**:
   - M11: Loại bỏ nút hủy khi đang RUNNING, ghi chú rõ ràng quy tắc của State machine §4.1.
   - M04: Điều chỉnh tab cấu hình LLM thành hiển thị thông tin chỉ đọc (Read-only System Parameters) từ biến môi trường hệ thống `.env`, bỏ nút lưu cấu hình không có API hỗ trợ; người dùng tùy biến tham số cụ thể tại form khởi tạo Job (M11) hoặc Thực nghiệm (M15).

## Hệ quả (Consequences)
- **Tích cực**:
  - Hợp đồng giữa Web API, AI Service, Database, Wireframe và Thực nghiệm đạt tính nhất quán 100%.
  - Cơ sở dữ liệu và API sẵn sàng lưu vết và tái lập toàn bộ dữ liệu khoa học của các bài đo thực nghiệm.
  - Quá trình chạy thực nghiệm loại trừ hoàn toàn nhiễu do biến đổi dữ liệu sandbox.
  - Các bộ kiểm tra schema tự động trên cả PHP (`TestDslSchemaTest`) và Python (`test_dsl_schema.py`) đều bắt đúng các lỗi thiếu trường.
- **Tiêu cực / Đánh đổi**: Cần cập nhật nhẹ migration `test_cases`, `experiments` và `experiment_bug_detections`. Vì dự án đang trong pha tiền G0 (database có thể chạy `make fresh`), thay đổi này hoàn toàn an toàn và không gây rủi ro dữ liệu.
