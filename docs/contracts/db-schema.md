# Lược đồ cơ sở dữ liệu chi tiết (PostgreSQL 16)

> **Trạng thái:** FINAL v1.0 — Chuẩn bị cho mốc Gate G0 (Design Freeze, 14/10/2026). Sau G0, mọi thay đổi cấu trúc bảng/cột/ràng buộc phải có ADR theo quy định tại `docs/DESIGN.md §0` & `§2`.  
> **Quy ước thiết kế:**  
> - Hệ quản trị CSDL: **PostgreSQL 16**.  
> - Tên bảng: số nhiều, quy chuẩn `snake_case` (ví dụ: `api_specs`, `test_cases`).  
> - Khóa chính (PK): `id bigint` tự tăng (`bigserial` / `GENERATED ALWAYS AS IDENTITY`).  
> - Khóa ngoại (FK): `<tên_bảng_số_ít>_id bigint REFERENCES ...`.  
> - Dấu thời gian: `created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP`, `updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP`.  
> - Dữ liệu động/tài liệu: lưu bằng kiểu `jsonb`.  
> - Kiểu liệt kê (Enum): lưu `varchar` kèm ràng buộc `CHECK (<cột> IN ('...'))` với giá trị viết HOA.  
> - Chỉ mục: Đặt tên theo chuẩn `<bảng>_<cột>_idx` hoặc `<bảng>_<cột>_unique`.

---

## Danh mục bảng nghiệp vụ (16 bảng)

1. [users](#1-users)
2. [projects](#2-projects)
3. [allowed_targets](#3-allowed_targets)
4. [api_specs](#4-api_specs)
5. [endpoints](#5-endpoints)
6. [business_rules](#6-business_rules)
7. [test_suites](#7-test_suites)
8. [generation_jobs](#8-generation_jobs)
9. [test_cases](#9-test_cases)
10. [llm_call_logs](#10-llm_call_logs)
11. [test_runs](#11-test_runs)
12. [test_results](#12-test_results)
13. [benchmarks](#13-benchmarks)
14. [benchmark_bugs](#14-benchmark_bugs)
15. [experiments](#15-experiments)
16. [experiment_bug_detections](#16-experiment_bug_detections)

---

### 1. `users`
Bảng lưu trữ thông tin tài khoản người dùng của nền tảng (không mở đăng ký tự do, do ADMIN cấp tài khoản theo `docs/DESIGN.md §1`).

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `name` | `varchar(255)` | No | | Họ tên hiển thị của người dùng |
| `email` | `varchar(255)` | No | | Email đăng nhập, lưu chữ thường (đã `trim`), duy nhất không phân biệt hoa/thường (`UNIQUE`, `UNIQUE (LOWER(email))`) |
| `password` | `varchar(255)` | No | | Mật khẩu băm (bcrypt) |
| `role` | `varchar(20)` | No | `'TESTER'` | Vai trò: `CHECK (role IN ('ADMIN', 'TESTER'))` |
| `is_active` | `boolean` | No | `true` | Cờ trạng thái hoạt động của tài khoản |
| `remember_token`| `varchar(100)` | Yes | `NULL` | Token "Remember me" của session Laravel |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `CONSTRAINT users_email_unique UNIQUE (email)`
  - `CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))` (S13-03: hai email chỉ khác hoa/thường là cùng một tài khoản; đăng nhập không phân biệt hoa/thường)
  - `CONSTRAINT users_role_check CHECK (role IN ('ADMIN', 'TESTER'))`

---

### 2. `projects`
Dự án kiểm thử do người dùng tạo để quản lý các đặc tả API, kịch bản kiểm thử và các lần chạy.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `name` | `varchar(255)` | No | | Tên dự án |
| `description` | `text` | Yes | `NULL` | Mô tả chi tiết dự án |
| `owner_id` | `bigint` | No | | Người sở hữu: `FK -> users(id) ON DELETE RESTRICT` |
| `default_target_url` | `varchar(500)` | Yes | `NULL` | Base URL mặc định của hệ thống cần test |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |
| `deleted_at` | `timestamptz` | Yes | `NULL` | Thời điểm xóa mềm (`SoftDeletes`) |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT`
  - `INDEX projects_owner_id_idx (owner_id)`
  - `INDEX projects_deleted_at_idx (deleted_at)`

---

### 3. `allowed_targets`
Danh sách trắng các đích (host/port) được phép gửi request kiểm thử từ Executor (chống SSRF theo `docs/DESIGN.md D07`). Do ADMIN quản lý.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `host` | `varchar(255)` | No | | Tên miền hoặc địa chỉ IP của host cho phép |
| `port` | `integer` | Yes | `NULL` | Cổng cụ thể (nếu `NULL` là cho phép mọi port của host) |
| `note` | `text` | Yes | `NULL` | Ghi chú mục đích (ví dụ: "Sandbox thanh toán nội bộ") |
| `created_by` | `bigint` | No | | ADMIN tạo: `FK -> users(id) ON DELETE RESTRICT` |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT`
  - `CONSTRAINT allowed_targets_host_port_unique UNIQUE (host, port)`

---

### 4. `api_specs`
Các phiên bản đặc tả OpenAPI 3.x (JSON/YAML) được người dùng tải lên trong dự án.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `project_id` | `bigint` | No | | `FK -> projects(id) ON DELETE CASCADE` |
| `version_label` | `varchar(50)` | No | | Nhãn phiên bản (ví dụ: `v1.0.0`, `2026-10-05`) |
| `source_filename` | `varchar(255)` | No | | Tên file gốc người dùng tải lên |
| `format` | `varchar(10)` | No | | Định dạng: `CHECK (format IN ('json', 'yaml'))` |
| `openapi_version` | `varchar(20)` | No | | Phiên bản OpenAPI (ví dụ: `3.0.3`, `3.1.0`) |
| `raw_content` | `text` | No | | Nội dung thô ban đầu (giới hạn $\le 5\text{MB}$); BOM UTF-8 ở đầu nội dung (nếu có) được bỏ trước khi lưu và phân tích |
| `normalized` | `jsonb` | Yes | `NULL` | Spec JSON đã resolve `$ref` và chuẩn hóa |
| `checksum` | `varchar(64)` | No | | Mã băm SHA-256 của `raw_content` đã lưu (sau khi bỏ BOM UTF-8 đầu nội dung) để chống trùng lặp |
| `status` | `varchar(20)` | No | `'UPLOADED'` | Trạng thái: `CHECK (status IN ('UPLOADED', 'PARSED', 'PARSE_FAILED'))` |
| `parse_errors` | `jsonb` | Yes | `NULL` | Danh sách lỗi chi tiết nếu parse thất bại |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE`
  - `CONSTRAINT api_specs_project_checksum_unique UNIQUE (project_id, checksum)`
  - `CONSTRAINT api_specs_format_check CHECK (format IN ('json', 'yaml'))`
  - `CONSTRAINT api_specs_status_check CHECK (status IN ('UPLOADED', 'PARSED', 'PARSE_FAILED'))`
  - `INDEX api_specs_project_id_status_idx (project_id, status)`

---

### 5. `endpoints`
Từng endpoint HTTP được trích xuất từ đặc tả OpenAPI sau khi parse thành công.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `api_spec_id` | `bigint` | No | | `FK -> api_specs(id) ON DELETE CASCADE` |
| `method` | `varchar(10)` | No | | Phương thức HTTP: `CHECK (method IN ('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'))` |
| `path` | `varchar(500)` | No | | Đường dẫn API (bắt đầu bằng `/`, ví dụ: `/items/{id}`) |
| `operation_id` | `varchar(255)` | Yes | `NULL` | operationId từ OpenAPI spec |
| `summary` | `text` | Yes | `NULL` | Tóm tắt chức năng |
| `description` | `text` | Yes | `NULL` | Mô tả chi tiết |
| `parameters` | `jsonb` | Yes | `NULL` | Khai báo tham số `path`, `query`, `header`, `cookie` |
| `request_body` | `jsonb` | Yes | `NULL` | Schema request body (content-types, required, properties) |
| `responses` | `jsonb` | No | | Cấu trúc phản hồi theo từng mã status code |
| `security` | `jsonb` | Yes | `NULL` | Yêu cầu phân quyền/xác thực cho endpoint |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (api_spec_id) REFERENCES api_specs(id) ON DELETE CASCADE`
  - `CONSTRAINT endpoints_spec_method_path_unique UNIQUE (api_spec_id, method, path)`
  - `CONSTRAINT endpoints_method_check CHECK (method IN ('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'))`
  - `INDEX endpoints_api_spec_id_idx (api_spec_id)`

---

### 6. `business_rules`
Ràng buộc nghiệp vụ viết bằng ngôn ngữ tự nhiên, làm ngữ cảnh bổ sung cho LLM sinh test theo chiến lược HYBRID (`docs/DESIGN.md §10`). Thực nghiệm không đọc bảng này: job LLM/HYBRID của thực nghiệm dùng snapshot `experiments.config.business_rules` lấy từ `ground-truth.yaml` (ADR-0006).

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `project_id` | `bigint` | No | | `FK -> projects(id) ON DELETE CASCADE` |
| `endpoint_id` | `bigint` | Yes | `NULL` | `FK -> endpoints(id) ON DELETE SET NULL` (NULL nếu là luật toàn cục của project) |
| `code` | `varchar(50)` | No | | Mã định danh luật (ví dụ: `BR-ITEM-001`) |
| `description` | `text` | No | | Nội dung quy tắc nghiệp vụ bằng văn bản tự nhiên |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE`
  - `FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE SET NULL`
  - `CONSTRAINT business_rules_project_code_unique UNIQUE (project_id, code)`
  - `INDEX business_rules_project_id_idx (project_id)`
  - `INDEX business_rules_endpoint_id_idx (endpoint_id)`

---

### 7. `test_suites`
Tập hợp các ca kiểm thử thuộc một dự án và gắn với một phiên bản đặc tả API cụ thể.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `project_id` | `bigint` | No | | `FK -> projects(id) ON DELETE CASCADE` |
| `api_spec_id` | `bigint` | No | | `FK -> api_specs(id) ON DELETE RESTRICT` |
| `name` | `varchar(255)` | No | | Tên bộ kiểm thử (ví dụ: `Smoke Test v1.0`) |
| `description` | `text` | Yes | `NULL` | Mô tả mục tiêu của suite |
| `created_by` | `bigint` | No | | `FK -> users(id) ON DELETE RESTRICT` |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE`
  - `FOREIGN KEY (api_spec_id) REFERENCES api_specs(id) ON DELETE RESTRICT`
  - `FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT`
  - `INDEX test_suites_project_id_idx (project_id)`
  - `INDEX test_suites_api_spec_id_idx (api_spec_id)`

---

### 8. `generation_jobs`
Tiến trình sinh test case bất đồng bộ (chạy qua Laravel queue worker theo `docs/DESIGN.md D10`).

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `project_id` | `bigint` | No | | `FK -> projects(id) ON DELETE CASCADE` |
| `api_spec_id` | `bigint` | No | | `FK -> api_specs(id) ON DELETE RESTRICT` |
| `test_suite_id` | `bigint` | No | | `FK -> test_suites(id) ON DELETE CASCADE` |
| `strategy` | `varchar(20)` | No | | Chiến lược sinh: `CHECK (strategy IN ('RULE', 'LLM', 'HYBRID'))` |
| `status` | `varchar(20)` | No | `'QUEUED'` | Trạng thái (§4.1): `CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'PARTIAL', 'FAILED'))` |
| `params` | `jsonb` | No | | Cấu hình: `endpoint_ids, techniques, model, temperature, seed, max_repair_attempts`; job của thực nghiệm có thêm `auth` (tên vai trò, vai trò theo operation — không token) và, với LLM/HYBRID, `business_rules` (snapshot, ADR-0006); job LLM/HYBRID nạp lại từ bản ghi thực nghiệm chính thức của showcase demo (không gọi LLM) có thêm `recorded_source_job_id` (id job gốc, không trả qua API; S14-06) |
| `stats` | `jsonb` | Yes | `NULL` | Thống kê kết quả: `{ generated, valid, invalid, repaired, duplicates }` |
| `error` | `text` | Yes | `NULL` | Chi tiết lỗi hệ thống nếu job FAILED. Job LLM/HYBRID lưu mã lỗi §6 ở dòng đầu, dòng sau (nếu có) là thông báo cho người dùng (S10-01) |
| `started_at` | `timestamptz` | Yes | `NULL` | Thời điểm bắt đầu chạy |
| `finished_at`| `timestamptz` | Yes | `NULL` | Thời điểm kết thúc chạy |
| `created_by` | `bigint` | No | | `FK -> users(id) ON DELETE RESTRICT` |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE`
  - `FOREIGN KEY (api_spec_id) REFERENCES api_specs(id) ON DELETE RESTRICT`
  - `FOREIGN KEY (test_suite_id) REFERENCES test_suites(id) ON DELETE CASCADE`
  - `FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT`
  - `CONSTRAINT generation_jobs_strategy_check CHECK (strategy IN ('RULE', 'LLM', 'HYBRID'))`
  - `CONSTRAINT generation_jobs_status_check CHECK (status IN ('QUEUED', 'RUNNING', 'SUCCEEDED', 'PARTIAL', 'FAILED'))`
  - `INDEX generation_jobs_status_idx (status)`
  - `INDEX generation_jobs_test_suite_id_idx (test_suite_id)`

---

### 9. `test_cases`
Ca kiểm thử được lưu trữ dưới dạng JSON tuân thủ chuẩn **Test DSL v1.0** (`docs/DESIGN.md D05`).

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `test_suite_id` | `bigint` | No | | `FK -> test_suites(id) ON DELETE CASCADE` |
| `endpoint_id` | `bigint` | No | | `FK -> endpoints(id) ON DELETE RESTRICT` |
| `generation_job_id` | `bigint` | Yes | `NULL` | `FK -> generation_jobs(id) ON DELETE SET NULL` (NULL nếu tạo thủ công `MANUAL`) |
| `source` | `varchar(20)` | No | | Nguồn gốc: `CHECK (source IN ('RULE', 'LLM', 'HYBRID', 'MANUAL'))` |
| `technique` | `varchar(30)` | No | | Kỹ thuật kiểm thử: `CHECK (technique IN ('HAPPY_PATH', 'BOUNDARY', 'EQUIVALENCE', 'INVALID_INPUT', 'MISSING_REQUIRED', 'TYPE_MISMATCH', 'AUTH', 'BUSINESS_RULE', 'SEQUENCE'))` |
| `title` | `varchar(255)` | No | | Tiêu đề của test case |
| `dsl` | `jsonb` | No | | Nội dung toàn vẹn theo Test DSL v1.0 |
| `dsl_version` | `varchar(10)` | No | `'1.0'` | Phiên bản DSL |
| `fingerprint` | `varchar(64)` | Yes | `NULL` | Mã băm SHA-256 chuẩn hóa phục vụ dedup (NULL khi case INVALID chưa thể chuẩn hóa) |
| `status` | `varchar(20)` | No | `'VALID'` | Trạng thái (§4.2): `CHECK (status IN ('VALID', 'INVALID', 'DUPLICATE', 'APPROVED', 'ARCHIVED'))` |
| `validation_errors` | `jsonb` | Yes | `NULL` | Chi tiết lỗi schema / ngữ nghĩa nếu status là INVALID |
| `repair_attempts` | `integer` | No | `0` | Số lần đã gọi LLM để sửa lỗi (tối đa 2 lần theo `docs/DESIGN.md D04`) |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (test_suite_id) REFERENCES test_suites(id) ON DELETE CASCADE`
  - `FOREIGN KEY (endpoint_id) REFERENCES endpoints(id) ON DELETE RESTRICT`
  - `FOREIGN KEY (generation_job_id) REFERENCES generation_jobs(id) ON DELETE SET NULL`
  - `CONSTRAINT test_cases_source_check CHECK (source IN ('RULE', 'LLM', 'HYBRID', 'MANUAL'))`
  - `CONSTRAINT test_cases_status_check CHECK (status IN ('VALID', 'INVALID', 'DUPLICATE', 'APPROVED', 'ARCHIVED'))`
  - `INDEX test_cases_suite_fingerprint_idx (test_suite_id, fingerprint)`
  - `INDEX test_cases_suite_status_idx (test_suite_id, status)`
  - `INDEX test_cases_endpoint_id_idx (endpoint_id)`

---

### 10. `llm_call_logs`
Nhật ký chi tiết các lời gọi sang mô hình LLM để phục vụ tái lập nghiên cứu và phân tích token/chi phí (`docs/DESIGN.md D09`).  
> ⚠️ **Quy tắc bất biến:** Bảng chỉ cho phép **INSERT**, tuyệt đối không cập nhật hay xóa bản ghi.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `generation_job_id` | `bigint` | No | | `FK -> generation_jobs(id) ON DELETE CASCADE` |
| `test_case_id` | `bigint` | Yes | `NULL` | `FK -> test_cases(id) ON DELETE SET NULL` (nếu lời gọi sinh/sửa ca cụ thể) |
| `provider` | `varchar(50)` | No | | Nhà cung cấp: `openai_compatible`, `ollama`, `fake` |
| `model` | `varchar(100)` | No | | Tên mô hình (ví dụ: `qwen2.5-coder:7b`) |
| `purpose` | `varchar(20)` | No | | Mục đích gọi: `CHECK (purpose IN ('GENERATE', 'REPAIR'))` |
| `prompt` | `jsonb` | No | | Toàn văn prompt có cấu trúc gửi sang LLM |
| `response_raw` | `text` | No | | Toàn văn nội dung LLM phản hồi |
| `temperature` | `double precision` | No | | Tham số temperature |
| `seed` | `bigint` | Yes | `NULL` | Seed dùng khi sinh ngẫu nhiên |
| `prompt_tokens` | `integer` | Yes | `NULL` | Số token đầu vào |
| `completion_tokens` | `integer` | Yes | `NULL` | Số token sinh ra |
| `latency_ms` | `integer` | No | | Thời gian phản hồi tính bằng mili-giây |
| `error` | `text` | Yes | `NULL` | Lỗi nếu cuộc gọi thất bại |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm ghi log |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (generation_job_id) REFERENCES generation_jobs(id) ON DELETE CASCADE`
  - `FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE SET NULL`
  - `CONSTRAINT llm_call_logs_purpose_check CHECK (purpose IN ('GENERATE', 'REPAIR'))`
  - `INDEX llm_call_logs_job_id_idx (generation_job_id)`
  - `INDEX llm_call_logs_test_case_id_idx (test_case_id)`

---

### 11. `test_runs`
Đợt thực thi một bộ kiểm thử trên môi trường API sandbox hoặc hệ thống mục tiêu.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `test_suite_id` | `bigint` | No | | `FK -> test_suites(id) ON DELETE CASCADE` |
| `target_base_url` | `varchar(500)` | No | | URL máy chủ đích thực thi (được kiểm tra theo allowlist D07) |
| `benchmark_variant` | `varchar(100)` | Yes | `NULL` | Tên biến thể (ví dụ: `clean` hoặc mã bug `BUG-001`) |
| `status` | `varchar(20)` | No | `'QUEUED'` | Trạng thái (§4.3): `CHECK (status IN ('QUEUED', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED'))` |
| `totals` | `jsonb` | Yes | `NULL` | Tổng hợp kết quả: `{ total, pass, fail, error, skipped }` |
| `triggered_by` | `bigint` | No | | `FK -> users(id) ON DELETE RESTRICT` |
| `started_at` | `timestamptz` | Yes | `NULL` | Thời điểm bắt đầu chạy |
| `finished_at`| `timestamptz` | Yes | `NULL` | Thời điểm kết thúc chạy |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (test_suite_id) REFERENCES test_suites(id) ON DELETE CASCADE`
  - `FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE RESTRICT`
  - `CONSTRAINT test_runs_status_check CHECK (status IN ('QUEUED', 'RUNNING', 'COMPLETED', 'FAILED', 'CANCELLED'))`
  - `INDEX test_runs_suite_id_idx (test_suite_id)`
  - `INDEX test_runs_status_idx (status)`

---

### 12. `test_results`
Kết quả thực thi chi tiết của từng test case trong một test run.  
> ⚠️ **Quy tắc:** Chỉ INSERT, không sửa đổi sau khi đã ghi nhận phán quyết (verdict).

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `test_run_id` | `bigint` | No | | `FK -> test_runs(id) ON DELETE CASCADE` |
| `test_case_id` | `bigint` | No | | `FK -> test_cases(id) ON DELETE RESTRICT` |
| `verdict` | `varchar(20)` | No | | Kết luận: `CHECK (verdict IN ('PASS', 'FAIL', 'ERROR', 'SKIPPED'))` |
| `duration_ms` | `integer` | No | | Thời gian thực thi tổng (mili-giây) |
| `request_log` | `jsonb` | No | | Chi tiết request thực tế đã gửi (method, URL, headers, body) |
| `response_log`| `jsonb` | No | | Chi tiết response nhận được (status, headers, body cắt $\le 64\text{KB}$) |
| `assertion_results`| `jsonb` | No | | Chi tiết kiểm tra từng assertion (type, expected, actual, passed) |
| `error_message` | `text` | Yes | `NULL` | Chi tiết lỗi mạng/kết nối nếu verdict là ERROR |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm ghi kết quả |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (test_run_id) REFERENCES test_runs(id) ON DELETE CASCADE`
  - `FOREIGN KEY (test_case_id) REFERENCES test_cases(id) ON DELETE RESTRICT`
  - `CONSTRAINT test_results_run_case_unique UNIQUE (test_run_id, test_case_id)`
  - `CONSTRAINT test_results_verdict_check CHECK (verdict IN ('PASS', 'FAIL', 'ERROR', 'SKIPPED'))`
  - `INDEX test_results_run_verdict_idx (test_run_id, verdict)`

---

### 13. `benchmarks`
Danh mục các hệ thống API mẫu (sandbox) có cài sẵn lỗi phục vụ đánh giá thực nghiệm.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `slug` | `varchar(100)` | No | | Mã định danh dạng URL (ví dụ: `benchmark-1-crud`) |
| `name` | `varchar(255)` | No | | Tên gọi benchmark (ví dụ: `Simple Store REST API`) |
| `description` | `text` | Yes | `NULL` | Giới thiệu nghiệp vụ của benchmark |
| `base_url` | `varchar(500)` | No | | Địa chỉ gốc nội bộ để gửi request kiểm thử |
| `version` | `varchar(20)` | No | `'v1.0'` | Phiên bản benchmark |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `CONSTRAINT benchmarks_slug_version_unique UNIQUE (slug, version)`

---

### 14. `benchmark_bugs`
Tập hợp các lỗi được cố tình cài đặt sẵn (seeded bugs) và thông tin ground truth để đánh giá oracle.

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `benchmark_id` | `bigint` | No | | `FK -> benchmarks(id) ON DELETE CASCADE` |
| `code` | `varchar(50)` | No | | Mã bug (ví dụ: `BUG-001`, `BUG-002`) |
| `method` | `varchar(10)` | No | | Phương thức HTTP của API bị cài lỗi |
| `path` | `varchar(500)` | No | | Đường dẫn của endpoint bị cài lỗi |
| `category` | `varchar(50)` | No | | Phân loại lỗi (`VALIDATION_MISSING`, `STATUS_CODE_WRONG`, `SCHEMA_MISMATCH`, `BOUNDARY_ERROR`, `BUSINESS_RULE_VIOLATION`, `SEQUENCE_ERROR`, `AUTH_ERROR`) |
| `description` | `text` | No | | Mô tả hành vi lỗi được cài sẵn |
| `toggle_flag` | `varchar(100)` | No | | Biến môi trường để kích hoạt lỗi trong container sandbox |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (benchmark_id) REFERENCES benchmarks(id) ON DELETE CASCADE`
  - `CONSTRAINT benchmark_bugs_benchmark_code_unique UNIQUE (benchmark_id, code)`
  - `INDEX benchmark_bugs_benchmark_id_idx (benchmark_id)`

---

### 15. `experiments`
Cấu hình và phiên chạy thực nghiệm so sánh định lượng giữa 3 chiến lược RULE, LLM và HYBRID (`docs/DESIGN.md §11`).

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `name` | `varchar(255)` | No | | Tên bài thực nghiệm |
| `benchmark_id` | `bigint` | No | | `FK -> benchmarks(id) ON DELETE RESTRICT` |
| `strategy` | `varchar(20)` | No | | Chiến lược so sánh: `CHECK (strategy IN ('RULE', 'LLM', 'HYBRID'))` |
| `config` | `jsonb` | No | | Cấu hình lặp: `{ model, seed, seeds, temperature, repetitions, reset_per_case, auth, business_rules }`; `seeds[r-1]` là seed của lần lặp r (mặc định giao thức 42, 1024, 2026 — `EXPERIMENT_SEEDS`), `seed = seeds[0]` (ADR-0003); `auth` (fixture xác thực, có token sandbox synthetic, hoặc null) và `business_rules` là snapshot `ground-truth.yaml` lúc tạo (ADR-0006); `reset_per_case` (boolean, từ `EXPERIMENT_RESET_PER_CASE`, thiếu = false) bật reset sandbox trước từng test case của lượt chạy thực nghiệm (ADR-0006, bổ sung 2026-10-12) |
| `status` | `varchar(20)` | No | `'PLANNED'` | Trạng thái (§4.4): `CHECK (status IN ('PLANNED', 'RUNNING', 'COMPLETED', 'FAILED'))` |
| `metrics` | `jsonb` | Yes | `NULL` | Bảng chỉ số tính toán được: validity_rate, executable_rate, bug_detection_rate, mutation_score... |
| `repetitions_data` | `jsonb` | Yes | `NULL` | Cấu trúc dữ liệu chi tiết từng lần lặp: mảng các object `[{ repetition, seed, generation_job_id, generation_job_ids, test_suite_id, clean_test_run_id, bug_runs }]`; `generation_job_ids` là mọi job đã nạp case vào suite (HYBRID: RULE seed rồi HYBRID, ADR-0006) |
| `started_at` | `timestamptz` | Yes | `NULL` | Thời điểm bắt đầu chạy thực nghiệm |
| `finished_at`| `timestamptz` | Yes | `NULL` | Thời điểm kết thúc |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm tạo |
| `updated_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm cập nhật cuối |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (benchmark_id) REFERENCES benchmarks(id) ON DELETE RESTRICT`
  - `CONSTRAINT experiments_strategy_check CHECK (strategy IN ('RULE', 'LLM', 'HYBRID'))`
  - `CONSTRAINT experiments_status_check CHECK (status IN ('PLANNED', 'RUNNING', 'COMPLETED', 'FAILED'))`
  - `INDEX experiments_benchmark_id_idx (benchmark_id)`
  - `INDEX experiments_status_idx (status)`

---

### 16. `experiment_bug_detections`
Ghi nhận kết quả phát hiện lỗi chi tiết cho từng bug và từng lần lặp (theo định nghĩa phát hiện lỗi tại `docs/DESIGN.md D14`).

| Tên cột | Kiểu dữ liệu | Nullable | Mặc định | Mô tả & Ràng buộc |
|---|---|---|---|---|
| `id` | `bigint` | No | Auto increment | Khóa chính |
| `experiment_id` | `bigint` | No | | `FK -> experiments(id) ON DELETE CASCADE` |
| `benchmark_bug_id` | `bigint` | No | | `FK -> benchmark_bugs(id) ON DELETE CASCADE` |
| `repetition` | `integer` | No | | Số thứ tự lần lặp (1, 2, 3...) |
| `detected` | `boolean` | No | `false` | `true` nếu có ít nhất 1 test PASS trên bản sạch và FAIL/ERROR trên bản có bug |
| `clean_test_run_id` | `bigint` | No | | `FK -> test_runs(id) ON DELETE CASCADE` (Lần chạy kiểm chứng trên bản sạch của repetition này) |
| `bug_test_run_id` | `bigint` | No | | `FK -> test_runs(id) ON DELETE CASCADE` (Lần chạy đánh giá trên biến thể bug của repetition này) |
| `detecting_test_case_ids`| `jsonb` | Yes | `NULL` | Danh sách mảng ID các test case đã phát hiện bug |
| `created_at` | `timestamptz` | No | `CURRENT_TIMESTAMP` | Thời điểm ghi nhận |

* **Chỉ mục & Ràng buộc:**
  - `PRIMARY KEY (id)`
  - `FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE`
  - `FOREIGN KEY (benchmark_bug_id) REFERENCES benchmark_bugs(id) ON DELETE CASCADE`
  - `FOREIGN KEY (clean_test_run_id) REFERENCES test_runs(id) ON DELETE CASCADE`
  - `FOREIGN KEY (bug_test_run_id) REFERENCES test_runs(id) ON DELETE CASCADE`
  - `CONSTRAINT exp_bug_det_unique UNIQUE (experiment_id, benchmark_bug_id, repetition)`
  - `INDEX exp_bug_det_experiment_idx (experiment_id)`
  - `INDEX exp_bug_det_clean_run_idx (clean_test_run_id)`
  - `INDEX exp_bug_det_bug_run_idx (bug_test_run_id)`
