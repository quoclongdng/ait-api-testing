# Đặc tả thiết kế — AI Testing Platform (AIT)

> Design specification of the platform (in Vietnamese): locked decisions D01–D15, architecture, state machines, database, API, environment variables, security checklist, generation pipeline, benchmarks and metrics. Code comments refer to it as `docs/DESIGN.md §N` or by decision id `Dxx`.

## 1. Dự án là gì

Nền tảng Web cho phép nhập đặc tả **OpenAPI 3.x**, **sinh ca kiểm thử REST API** theo ba chiến lược **Rule-based, LLM, Hybrid**, kiểm tra tính hợp lệ (validation, repair, dedup), **thực thi trên sandbox** và **đánh giá bằng assertion/test oracle**. Kèm theo là **bộ benchmark API có lỗi cài sẵn và ground truth** để so sánh định lượng ba chiến lược.

Người dùng: người nghiên cứu, giảng viên và sinh viên kiểm thử phần mềm. Môi trường: localhost/Docker/sandbox nội bộ.

| Actor | Mô tả | Quyền |
|---|---|---|
| **ADMIN** | Quản trị hệ thống (trưởng nhóm) | Mọi quyền của TESTER; quản lý người dùng (tạo, khóa, đổi vai trò); quản lý `allowed_targets` (M04); xem thông tin LLM provider/model hệ thống (M04 chỉ đọc); quản lý benchmark; xem mọi project |
| **TESTER** | Thành viên nghiên cứu, người dùng nghiệp vụ | Tạo, sửa project của mình; nhập OpenAPI; quản lý business rules, test suite, test case; sinh test (RULE/LLM/HYBRID); chạy test trên target thuộc allowlist; xem và xuất báo cáo; chạy thực nghiệm |
| *Khách* | Không đăng nhập | Không có quyền gì ngoài `/` (chuyển tới trang đăng nhập), trang đăng nhập và `/up`. **Không có tự đăng ký**: ADMIN tạo tài khoản |

---

## 2. Quyết định đã chốt (LOCKED)

Muốn đổi thì phải có ADR (`docs/adr/NNNN-*.md`) được duyệt.

| ID | Quyết định | ADR |
|---|---|---|
| D01 | Web: **Laravel 12, PHP 8.4, PostgreSQL 16, Blade + Tailwind 4 + Alpine.js**, build bằng Vite. Không dùng SPA framework | 0001 |
| D02 | AI: service riêng **Python 3.12 + FastAPI** (`ai-service/`), không truy cập DB, giao tiếp HTTP JSON theo `docs/contracts/ai-service.openapi.yaml` | 0001 |
| D03 | **PASS/FAIL chỉ do Assertion Engine (PHP, tất định) quyết định.** LLM không bao giờ kết luận kết quả test | 0001 |
| D04 | Mọi test case (kể cả từ LLM) phải qua **JSON Schema + kiểm tra ngữ nghĩa với spec** (endpoint, field, status tồn tại) và **dedup** trước khi được chạy. Repair tối đa `AI_MAX_REPAIR_ATTEMPTS` (mặc định 2). Web validate lại lần cuối dù ai-service đã validate | 0001 |
| D05 | **Test DSL v1.0** dạng JSON, schema duy nhất tại `docs/contracts/test-dsl.schema.json` (JSON Schema 2020-12), dùng chung cho PHP và Python. Đổi phiên bản DSL phải có ADR | 0002 |
| D06 | OpenAPI **3.0.x và 3.1.x** (JSON/YAML). Parser PHP: `devizzent/cebe-php-openapi`; JSON Schema PHP: `opis/json-schema`; Python: `jsonschema` | — |
| D07 | Executor chỉ gửi request tới host thuộc `ALLOWED_TARGET_HOSTS` ∪ bảng `allowed_targets`. Không follow redirect. Ngoài danh sách thì trả `TARGET_NOT_ALLOWED` (chống SSRF) | — |
| D08 | Mỗi phụ thuộc ngoài có **interface + bản thật + bản Fake**, chọn qua config: `AiGeneratorClient`, `TargetHttpClient` (PHP), `LlmProvider` (Python). **CI và test không gọi LLM thật** | 0001 |
| D09 | LLM mặc định là **OpenAI-compatible** (Ollama local, model mặc định `qwen2.5-coder:7b`). Mọi lời gọi LLM lưu nguyên văn (prompt, response, model, temperature, seed, tokens, latency) vào `llm_call_logs` để tái lập | — |
| D10 | Tác vụ dài (sinh test, chạy test, thực nghiệm) chạy qua **Laravel queue driver `database`**, hai hàng đợi: `default` (sinh RULE, lượt chạy) và `llm` (sinh LLM/HYBRID, thực nghiệm). Dev và `make experiment`: một service `worker` (`--queue=default,llm`); production: `worker` (`default`) và `worker-llm` (`llm`), để job LLM dài không chặn job RULE và lượt chạy (S14-06) | 0009 |
| D11 | Xác thực bằng **session Laravel**, phân quyền bằng cột `users.role` (`ADMIN`/`TESTER`) và Policies. Không dùng Sanctum hay token công khai ở MVP | — |
| D12 | API JSON nội bộ có prefix `/api/v1`, mọi lỗi theo **envelope chuẩn §6** | — |
| D13 | Benchmark API viết bằng **FastAPI** trong `benchmark/<slug>/`, seeded bug bật bằng biến môi trường, ground truth trong `ground-truth.yaml`. Ground truth có thể khai báo fixture xác thực synthetic (`auth`: vai trò, token sandbox công khai, vai trò theo operation) và `business_rules`; thực nghiệm snapshot cả hai vào `experiments.config` khi tạo, executor tự gửi `Authorization: Bearer {{auth_token_<role>}}` tới operation có security cho case không phải AUTH, bộ sinh chỉ thấy tên vai trò (không token); lượt chạy M12 có host target là benchmark của nền tảng (danh sách `RUN_RESET_BENCHMARK_HOSTS`) cũng dùng fixture của benchmark đó, target khác thì không (bổ sung 2026-10-14) | 0006 |
| D14 | Một bug được tính là **phát hiện** khi có ≥1 test PASS trên biến thể sạch và FAIL/ERROR trên biến thể có bug. Test FAIL trên biến thể sạch bị tính là false positive | — |
| D15 | Môi trường dev dùng PHP built-in server (`php -S`, không dùng `artisan serve` vì nó lọc biến môi trường) trong Docker. Không tối ưu production ở MVP | — |

---

## 3. Kiến trúc

```
Trình duyệt (ADMIN/TESTER)
        │ HTTP (session)
        ▼
┌───────────────────── web (Laravel 12) ─────────────────────┐        ┌──────────────┐
│ Controllers (mỏng) → Actions → Services → Eloquent ────────┼──────▶ │ PostgreSQL 16│
│                         │            │                     │        └──────────────┘
│                         │            ├─ OpenApi parser     │
│                         │            ├─ RuleGenerator      │
│                         │            ├─ DslValidator       │
│                         │            ├─ Executor ──TargetHttpClient──▶ benchmark APIs / target (allowlist)
│                         │            └─ AssertionEngine    │
│ Jobs (worker, queue) ───┘                                  │
│      └─ AiGeneratorClient ── HTTP JSON ─┐                  │
└─────────────────────────────────────────┼──────────────────┘
                                          ▼
                     ┌──────── ai-service (FastAPI) ────────┐
                     │ prompt → LlmProvider → validate →    │──▶ Ollama / OpenAI-compatible
                     │ repair → dedup → DSL + raw logs      │
                     └──────────────────────────────────────┘
```

Chi tiết các luồng: `docs/architecture/overview.md`. Lý do chọn kiến trúc: `docs/adr/0001-hybrid-laravel-python-architecture.md`.

**Quy tắc phân lớp (web):** Controller chỉ làm `validate (FormRequest) → 1 Action → response`. Nghiệp vụ nằm trong `app/Actions/<Domain>/` (một use case mỗi lớp, method `execute`) và `app/Services/<Domain>/`. Gọi ra ngoài thì chỉ đi qua interface trong `app/Contracts/`. DTO đặt trong `app/Data/`, enum trong `app/Enums/`. Controller không gọi `Http::` hay `DB::` trực tiếp.

### Cây thư mục chuẩn

```
.
├── docs/DESIGN.md                     # luật gốc (file này)
├── README.md
├── Makefile                      # make up | down | fresh | seed | test | lint | analyse | format | secrets
├── .env.example                  # biến cho docker compose
├── .github/
│   ├── workflows/ci.yml          # web, ai-service, secrets (gitleaks)
│   ├── pull_request_template.md
│   └── CODEOWNERS
├── docker/
│   ├── docker-compose.yml        # postgres, web, worker, vite, ai-service, (ollama: profile llm)
│   ├── web/{Dockerfile,entrypoint.sh}
│   ├── ai-service/Dockerfile
│   └── postgres/init/            # tạo DB ait_test
├── docs/
│   ├── adr/                      # 0000-template.md, 0001-...
│   ├── architecture/             # overview.md, (screens.md, experiment-design.md)
│   └── contracts/                # web-api.openapi.yaml, ai-service.openapi.yaml,
│                                 # test-dsl.schema.json, db-schema.md, examples/
├── web/                          # Laravel 12
│   ├── app/
│   │   ├── Actions/<Domain>/     # use case: System, Projects, Specs, Generation, Runs, Experiments
│   │   ├── Contracts/            # AiGeneratorClient, TargetHttpClient
│   │   ├── Data/                 # DTO readonly
│   │   ├── Enums/                # ErrorCode, (UserRole, statuses)
│   │   ├── Exceptions/           # ApiException
│   │   ├── Http/Controllers/{Api/V1,Web}/  # mỏng
│   │   ├── Http/Requests/        # FormRequest
│   │   ├── Jobs/
│   │   ├── Models/
│   │   ├── Policies/
│   │   └── Services/<Domain>/    # Ai/, Target/, OpenApi/, Generation/, Execution/, Assertion/
│   ├── config/ait.php
│   ├── routes/{web,api}.php
│   └── tests/{Feature,Unit}/
├── ai-service/                   # Python 3.12 + FastAPI (uv)
│   ├── app/{api,contracts,providers,services}/
│   └── tests/
└── benchmark/                    # API sandbox có seeded bug + ground truth
```

---

## 4. State machine

Trạng thái chỉ được đổi qua Action. Chuyển trạng thái không có trong bảng thì ném `ApiException(INVALID_STATE_TRANSITION)`.

### 4.1 `generation_jobs.status`
| Từ | Sang | Khi |
|---|---|---|
| — | `QUEUED` | TESTER tạo job |
| `QUEUED` | `RUNNING` | worker nhận job |
| `RUNNING` | `SUCCEEDED` | mọi endpoint xử lý xong không lỗi, ≥1 case VALID hoặc mọi case đều `DUPLICATE` (không có case mới; ADR-0009) |
| `RUNNING` | `PARTIAL` | có endpoint lỗi (không sinh được case hoặc chỉ có case INVALID) nhưng vẫn có case VALID |
| `RUNNING` | `FAILED` | lỗi toàn bộ (ai-service down, 0 case VALID và có case INVALID hoặc endpoint không sinh được case…) |
| `QUEUED` | `FAILED` | hủy trước khi chạy |

### 4.2 `test_cases.status`
| Từ | Sang | Khi |
|---|---|---|
| — | `VALID` / `INVALID` / `DUPLICATE` | sau validate + dedup |
| `INVALID` | `VALID` | sửa tay (MANUAL) và validate lại thành công |
| `VALID` | `APPROVED` | TESTER duyệt |
| `VALID`, `APPROVED` | `ARCHIVED` | TESTER lưu trữ |
| `APPROVED` | `VALID` | sửa DSL (phải duyệt lại) |

Chỉ case `VALID` hoặc `APPROVED` được đưa vào TestRun.

### 4.3 `test_runs.status`
`QUEUED → RUNNING → COMPLETED | FAILED`; `QUEUED|RUNNING → CANCELLED` (TESTER hủy). Verdict của từng `test_results`: `PASS | FAIL | ERROR | SKIPPED` (`ERROR` khi không gửi được request hoặc timeout; `FAIL` khi assertion sai).

### 4.4 `experiments.status`
`PLANNED → RUNNING → COMPLETED | FAILED`.

### 4.5 `api_specs.status`
`UPLOADED → PARSED | PARSE_FAILED`. Spec `PARSE_FAILED` không thể dùng để sinh test.

---

## 5. Cơ sở dữ liệu

Hợp đồng chi tiết: **`docs/contracts/db-schema.md`**. Các bảng nghiệp vụ: `users, projects, allowed_targets, api_specs, endpoints, business_rules, test_suites, generation_jobs, test_cases, llm_call_logs, test_runs, test_results, benchmarks, benchmark_bugs, experiments, experiment_bug_detections`.

Quy tắc: PostgreSQL 16, JSON dùng `jsonb`, enum lưu chữ HOA, `llm_call_logs` và `test_results` chỉ được INSERT. Migration phải chạy `migrate:fresh` sạch và có rollback (`down`). `experiments.config` lưu snapshot `auth` và `business_rules` của ground truth; `repetitions_data[].generation_job_ids` liệt kê mọi job sinh của lần lặp (ADR-0006).

---

## 6. API

- Prefix `/api/v1`, JSON, xác thực bằng session (D11). Hợp đồng: `docs/contracts/web-api.openapi.yaml`.
- Thành công: `{ "data": ... }`, danh sách thì thêm `"meta": { current_page, per_page, total }`.
- **Lỗi (envelope bắt buộc)**, do `App\Http\ApiErrorRenderer` render:

```json
{
  "error": { "code": "VALIDATION_FAILED", "message": "The given data was invalid.", "details": { "name": ["..."] } },
  "trace_id": "giá trị header X-Request-Id hoặc UUID"
}
```

| Mã lỗi | HTTP | Khi nào |
|---|---|---|
| `VALIDATION_FAILED` | 422 | Dữ liệu vào sai |
| `UNAUTHENTICATED` | 401 | Chưa đăng nhập |
| `FORBIDDEN` | 403 | Sai vai trò hoặc không sở hữu tài nguyên |
| `NOT_FOUND` | 404 | Không tồn tại |
| `METHOD_NOT_ALLOWED` | 405 | Sai method |
| `CONFLICT` | 409 | Trùng (ví dụ spec cùng checksum) |
| `INVALID_STATE_TRANSITION` | 409 | Chuyển trạng thái trái §4 |
| `SPEC_PARSE_FAILED` | 422 | OpenAPI không hợp lệ hoặc không hỗ trợ |
| `DSL_INVALID` | 422 | Test case không qua schema hoặc kiểm tra ngữ nghĩa |
| `TARGET_NOT_ALLOWED` | 422 | Target ngoài allowlist (D07) |
| `RATE_LIMITED` | 429 | Quá giới hạn |
| `LLM_PROVIDER_ERROR` | 502 | LLM lỗi hoặc trả dữ liệu không dùng được |
| `AI_SERVICE_UNAVAILABLE` | 503 | Không gọi được ai-service |
| `INTERNAL_ERROR` | 500 | Lỗi khác (ẩn chi tiết khi `APP_DEBUG=false`) |

Thêm mã lỗi mới thì cập nhật đồng thời `App\Enums\ErrorCode`, bảng này và OpenAPI.

**Danh mục tài nguyên (thiết kế, hoàn thiện ở S0-03):**

| Method & path | Mô tả | Quyền |
|---|---|---|
| `GET /health` | Trạng thái web, DB, ai-service | công khai (**đã có**) |
| `GET/POST /projects`, `GET/PATCH/DELETE /projects/{id}` | Project | TESTER (chủ sở hữu), ADMIN |
| `POST /projects/{id}/specs`, `GET /specs/{id}`, `GET /specs/{id}/endpoints` | Nhập và xem OpenAPI | như trên |
| `GET/POST /projects/{id}/business-rules` … | Ràng buộc nghiệp vụ | như trên |
| `GET/POST /projects/{id}/suites`, `GET /suites/{id}` | Test suite | như trên |
| `POST /suites/{id}/generation-jobs`, `GET /generation-jobs/{id}` | Sinh test | như trên |
| `GET /suites/{id}/test-cases`, `GET/PATCH /test-cases/{id}`, `POST /test-cases/{id}/approve`, `POST /test-cases/{id}/archive` | Test case | như trên |
| `POST /suites/{id}/runs`, `GET /runs/{id}`, `GET /runs/{id}/results`, `POST /runs/{id}/cancel`, `GET /runs/{id}/export?format=json\|csv\|html` | Chạy và báo cáo | như trên |
| `GET/POST /experiments`, `GET /experiments/{id}`, `GET /experiments/{id}/export?format=json\|csv` | Thực nghiệm, xuất dữ liệu thô (ADR-0003); config có snapshot `auth`/`business_rules`, mỗi lần lặp có `generation_job_ids` (ADR-0006) | TESTER, ADMIN |
| `GET/POST/PATCH /admin/users`, `GET/POST/DELETE /admin/allowed-targets`, `GET/POST/DELETE /admin/benchmarks` (xóa chỉ khi chưa có thực nghiệm dùng, ngược lại 409; `version` dạng số chuẩn hóa `1.0` → `v1.0`, slug có ground truth chỉ nhận đúng phiên bản của ground truth, S14-06) | Quản trị | ADMIN |

ai-service: `GET /health` (**đã có**), `POST /v1/generate` (S5). Xem `docs/contracts/ai-service.openapi.yaml`.

---

## 7. Biến môi trường

Chỉ commit `.env.example`. Thêm biến mới thì cập nhật bảng này và file `.env.example` tương ứng trong cùng PR.

| Biến | File | Mặc định | Ý nghĩa |
|---|---|---|---|
| `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` | `.env` | ait / ait / (đặt cục bộ) | DB trong Docker, compose truyền vào web và worker |
| `POSTGRES_PORT`, `WEB_PORT`, `AI_SERVICE_PORT` | `.env` | 5433, 8000, 8001 | Cổng mở ra host; postgres và ai-service (cùng Ollama 11434) chỉ bind `127.0.0.1` vì ai-service không có xác thực (S10-01) |
| `BENCHMARK_PORT`, `BENCHMARK_ORDER_PORT`, `BENCHMARK_BOOKING_PORT` | `.env` | 8002, 8003, 8004 | Cổng host của benchmark #1, #2 và #3 |
| `EXPERIMENT_LLM_PROVIDER`, `EXPERIMENT_LLM_BASE_URL`, `EXPERIMENT_LLM_CONTEXT_TOKENS`, `EXPERIMENT_MODEL`, `EXPERIMENT_TEMPERATURE`, `EXPERIMENT_SEEDS`, `EXPERIMENT_LLM_TIMEOUT_SECONDS`, `EXPERIMENT_GENERATE_BUDGET_SECONDS`, `EXPERIMENT_AI_SERVICE_TIMEOUT`, `EXPERIMENT_LLM_CASES_PER_CALL` | `.env` (tùy chọn) | openai_compatible, http://host.docker.internal:11434/v1, 16384, qwen2.5-coder:7b, 0.2, 42,1024,2026, 300, 1320, 1380, 3 | Ghi đè của `docker/docker-compose.experiment-llm.yml` cho stack thực nghiệm (S12-02): ép ai-service sang LLM thật (cùng `AI_MAX_REPAIR_ATTEMPTS=2`, `AI_LLM_OUTPUT_TOKENS_PER_CASE=400`, `AI_MAX_PROMPT_CHARS=36000`) và web/worker sang ai-service/target/sandbox thật. Mặc định theo chuỗi thời gian S11-02 (ADR-0005). `make experiment` tự đặt từ `MODEL`, `TEMPERATURE`, `SEEDS`, `OLLAMA`, `LLM_CONTEXT_TOKENS`, `LLM_TIMEOUT`, `GENERATE_BUDGET`, `CASES_PER_CALL` (web timeout = budget + 60; budget ≤ 1380) |
| `APP_LOCALE` | `web/.env` | vi | Ngôn ngữ UI |
| `APP_DISPLAY_TIMEZONE` | `web/.env` | Asia/Ho_Chi_Minh | Múi giờ hiển thị thời gian trên UI và file xuất (M11–M13…); dữ liệu vẫn lưu theo UTC (S10-01) |
| `SESSION_COOKIE` | `web/.env` | ait_session | Tên cookie session dùng chung cho dashboard và API nội bộ |
| `AI_SERVICE_DRIVER` | `web/.env` | http | `http` \| `fake` |
| `AI_SERVICE_URL` | `web/.env` | http://ai-service:8001 | URL ai-service |
| `AI_SERVICE_TIMEOUT` | `web/.env` | 1380 | Giây chờ một `POST /v1/generate`; ≥ `AI_GENERATE_BUDGET_SECONDS` + 60 (web kiểm tra trước mỗi lần sinh; `web/.env` cũ còn 120 phải sửa tay) và nhỏ hơn timeout `GenerateTestsJob`/`RunExperimentStepJob` (1500) (S11-02) |
| `TARGET_CLIENT_DRIVER` | `web/.env` | http | `http` \| `fake` |
| `TARGET_DEFAULT_TIMEOUT_MS` | `web/.env` | 10000 | Timeout mỗi request tới target |
| `ALLOWED_TARGET_HOSTS` | `web/.env` | localhost,127.0.0.1,benchmark,benchmark-order,benchmark-booking,simple-store,ecommerce-order,appointment-booking | Allowlist mặc định (D07); gồm tên service Docker và alias bản sạch của 3 benchmark; `web/.env.example` thêm sẵn 35 hostname sandbox benchmark (in bằng `benchmark/tools/gen_variant_compose.py --print-allowlist`, S12-02); `base_url` trong `ground-truth.yaml` là alias nội bộ `http://<slug>:8000` |
| `BENCHMARK_PATH` | `web/.env` | `../benchmark` (so với `web/`) | Thư mục chứa `benchmark/<slug>/{ground-truth.yaml,openapi.yaml}` cho M14/M15 (S9-02) |
| `BENCHMARK_SANDBOX_DRIVER` | `web/.env` | url_template | `url_template` \| `fake`: cách thực nghiệm tới biến thể sạch/bug của benchmark (S9-02) |
| `BENCHMARK_VARIANT_URL_TEMPLATE` | `web/.env` | (trống) | Base URL của sandbox chỉ bật một bug, ví dụ `http://{slug}-{code_lower}:8000` (khớp hostname trong `docker/docker-compose.experiment.yml`, S12-02); trống thì không tạo được thực nghiệm (S9-02) |
| `ADMIN_DEFAULT_PASSWORD` | `web/.env` | (trống) | **Secret**, không commit. Mật khẩu ADMIN khởi tạo `admin@ait.local` do seeder tạo; trống thì seeder sinh mật khẩu ngẫu nhiên và in ra một lần |
| `DEMO_PASSWORD` | `web/.env` | (trống) | **Secret**, không commit. Mật khẩu TESTER demo `demo@ait.local` do `DemoSeeder` tạo (`make demo`/`make demo-seed`, S14-01); trống thì seeder sinh mật khẩu ngẫu nhiên và in ra một lần. Khi `APP_ENV=production` không seeder nào tạo tài khoản; `DemoSeeder` từ chối nếu thiếu `--force` (ADR-0007) |
| `DEMO_SHOWCASE_ENABLED` | `web/.env` | false | `true` chỉ trên site demo (S14-05, ADR-0007 bổ sung 2026-10-13): `php artisan ait:demo:showcase` tạo/khôi phục 4 tài khoản demo **công khai** mật khẩu `123456` (ADMIN `admin@demo.ait.local`; TESTER `tester@`, `tester2@`, `tester3@demo.ait.local`), import 3 benchmark, 3 dự án mẫu (RULE + LLM/HYBRID bản ghi) và 9 thực nghiệm chính thức (M15 chỉ xem); trang đăng nhập liệt kê tài khoản |
| `EXPERIMENTS_ENABLED` | `web/.env` | true | `false` = bản triển khai không có sandbox seeded bug: M15 và `POST /api/v1/experiments` từ chối tạo thực nghiệm (422, thông báo tiếng Việt), xem/xuất thực nghiệm cũ vẫn được (production: false, ADR-0007) |
| `TRUSTED_PROXIES` | `web/.env` | (trống) | Proxy được tin header `X-Forwarded-*`: trống = không tin; `*` = hop gọi trực tiếp (production: nginx của máy chủ, cổng chỉ bind `127.0.0.1`); hoặc danh sách IP/CIDR và từ khóa `cloudflare`. `CF-Connecting-IP` chỉ được dùng khi request qua proxy tin cậy và proxy đó thấy edge Cloudflare (ADR-0007) |
| `TARGET_BLOCK_PRIVATE_NETWORKS` | `web/.env` | true khi `APP_ENV=production`, ngược lại false | Target do ADMIN thêm ở M04 không được là/resolve tới loopback hay mạng riêng; link-local/metadata (`169.254.0.0/16`, `fe80::/10`…) luôn bị chặn với mọi target. Production còn loại mục loopback khỏi `ALLOWED_TARGET_HOSTS` khi khởi động (D07, ADR-0007) |
| `SECURITY_HEADERS_ENABLED`, `SECURITY_HSTS_MAX_AGE` | `web/.env` | true, 31536000 | Header bảo mật (CSP có nonce, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`) cho mọi response; HSTS chỉ gửi qua HTTPS (0 = tắt). Phản hồi cho người đã đăng nhập (kể cả file xuất) luôn có `Cache-Control: no-store, no-transform, private` kể cả khi tắt biến này, để trình duyệt không lưu và CDN (Cloudflare Web Analytics) không chèn script (S14-06, ADR-0009) |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_MAX_ATTEMPTS_PER_IP`, `LOGIN_IP_DECAY_SECONDS` | `web/.env` | 5, 20, 600 | Giới hạn đăng nhập sai: theo email+IP mỗi phút, và theo IP trên mọi email trong `LOGIN_IP_DECAY_SECONDS` giây (chống dò mật khẩu hàng loạt) |
| `RATE_LIMIT_{GENERATION,TEST_RUNS,EXPERIMENTS,SPEC_UPLOADS}_PER_{MINUTE,HOUR}` | `web/.env` (tùy chọn) | 10/60, 10/120, 10/20, 10/60 | Giới hạn mỗi người dùng theo phút và theo giờ cho tạo job sinh test, lượt chạy, thực nghiệm, upload spec (§9); form web và `/api/v1` dùng chung bộ đếm |
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `SESSION_SECURE_COOKIE`, `LOG_CHANNEL`, `LOG_DAILY_DAYS` | `web/.env` | local, true, http://localhost:8000, (trống), stack, 14 | Production: `production`, `false`, URL https của máy chủ, `true`, `daily`, `14`. `APP_DEBUG=true` khi production bị ép về false và ghi log `critical`; `APP_URL` https thì mọi URL sinh ra dùng https (ADR-0007) |
| `BENCHMARK_CLEAN_URL_TEMPLATE` | `web/.env` | http://{slug}:8000 | Base URL biến thể sạch mà worker gọi được trong Docker (`{slug}`, `{version}`, `{base_url}`); trống thì dùng `benchmarks.base_url` (S11-02, ADR-0003) |
| `BENCHMARK_RESET_TIMEOUT_MS` | `web/.env` | 10000 | Timeout của `POST <variant>/__admin/reset` trước mỗi lượt chạy bản sạch/biến thể bug (S11-02) |
| `EXPERIMENT_SEEDS` | `web/.env` | 42,1024,2026 | Seed từng lần lặp của thực nghiệm (experiment-design §4.2); lưu vào `experiments.config.seeds` (S11-02) |
| `EXPERIMENT_RESET_PER_CASE` | `web/.env` | true | Reset sandbox (`POST <variant>/__admin/reset`) trước từng test case của lượt chạy thực nghiệm, bản sạch và mọi biến thể bug, mọi chiến lược; lưu vào `experiments.config.reset_per_case`; lượt chạy M12 theo `RUN_RESET_BENCHMARK_HOSTS` (ADR-0006 bổ sung 2026-10-12, S11-01) |
| `RUN_RESET_BENCHMARK_HOSTS` | `web/.env` | benchmark,benchmark-order,benchmark-booking,simple-store,ecommerce-order,appointment-booking | Lượt chạy M12 có host target thuộc danh sách này reset benchmark (`POST <scheme://host:port>/__admin/reset`) trước từng test case, để chạy lại cùng suite trên benchmark sạch dùng chung cho cùng verdict; reset lỗi thì case đó `ERROR`, không gửi; target khác không bao giờ bị reset; trống thì M12 không reset (ADR-0006 bổ sung 2026-10-13, S13-03) |
| `DB_QUEUE_RETRY_AFTER` | `web/.env` | 1900 | Giây trước khi queue `database` nhả lại job đang chạy; phải lớn hơn `--timeout` của worker (1800) và timeout job dài nhất (`RunExperimentStepJob` 1500) |
| `AI_LLM_PROVIDER` | `ai-service/.env` | fake | `fake` \| `openai_compatible` \| `disabled` (máy chủ không có LLM: `/health` báo `disabled`, `/v1/generate` trả 502 `reason: LLM_DISABLED`, web chỉ cho RULE; mặc định của production, ADR-0007) |
| `AI_LLM_BASE_URL` | `ai-service/.env` | http://ollama:11434/v1 | Endpoint OpenAI-compatible |
| `AI_LLM_API_KEY` | `ai-service/.env` | (trống) | **Secret**, không commit |
| `AI_LLM_MODEL` | `ai-service/.env` | qwen2.5-coder:7b | Model |
| `AI_LLM_TIMEOUT_SECONDS`, `AI_LLM_TEMPERATURE`, `AI_LLM_SEED` | `ai-service/.env` | 300, 0.2, 42 | Tham số gọi LLM; timeout của một lời gọi, luôn bị cắt theo thời gian còn lại của `AI_GENERATE_BUDGET_SECONDS`; `AI_LLM_SEED` dùng khi `options.seed` là null; `AI_LLM_TEMPERATURE` là temperature mặc định báo qua `GET /health` (ADR-0004), còn `options.temperature` là trường bắt buộc của `POST /v1/generate` theo hợp đồng nên lời gọi luôn dùng giá trị request gửi (S13-03) |
| `AI_LLM_RATE_LIMIT_RETRIES` | `ai-service/.env` | 3 | Nhà cung cấp trả HTTP 429/503 (giới hạn tốc độ, ví dụ gói miễn phí Groq): chờ theo `Retry-After`/`x-ratelimit-reset-*` (tối đa 60 s mỗi lần, mặc định 2/4/8 s) rồi thử lại tối đa N lần; cả lời gọi kể cả thời gian chờ không vượt timeout của nó (đã bị cắt theo `AI_GENERATE_BUDGET_SECONDS`); 0 = không thử lại |
| `AI_MAX_REPAIR_ATTEMPTS` | `ai-service/.env` | 2 | Trần số lần repair (0-10); giới hạn thực tế = min(trần hệ thống, options.max_repair_attempts) |
| `AI_GENERATE_BUDGET_SECONDS` | `ai-service/.env` | 1320 | Ngân sách thời gian cho một `POST /v1/generate`: một lời gọi GENERATE cho mỗi endpoint (theo thứ tự), sau đó repair trong thời gian còn lại; hết thì bỏ qua endpoint/repair còn lại và trả kết quả một phần (job `PARTIAL`). Đủ cho benchmark lớn nhất (ecommerce-order, 13 endpoint, ~60–90 s/endpoint ở ~18 token/s). Phải ≤ `AI_SERVICE_TIMEOUT` − 60: ai-service báo giá trị này qua `GET /health` (`generate_budget_seconds`), web từ chối sinh test LLM/HYBRID (job `FAILED`, `AI_SERVICE_UNAVAILABLE`, chưa gọi LLM) và M04 cảnh báo khi chuỗi bị vi phạm (S11-02) |
| `AI_LLM_CASES_PER_CALL` | `ai-service/.env` | 3 | Số case yêu cầu trong một lời gọi GENERATE (mỗi endpoint một lời gọi); thực tế = min(`options.max_cases_per_endpoint`, giá trị này) (S11-02) |
| `AI_LLM_OUTPUT_TOKENS_PER_CASE` | `ai-service/.env` | 400 | Token output dự trù cho một case: `max_tokens` GENERATE = số case × giá trị này + 256, REPAIR = 2 × giá trị này + 256, tối đa nửa context (S11-02) |
| `AI_MAX_PROMPT_CHARS` | `ai-service/.env` | 36000 | Số ký tự tối đa của một prompt; vượt thì trả 422 `VALIDATION_FAILED` (generate) hoặc bỏ qua repair. Phải vừa `AI_LLM_CONTEXT_TOKENS` (36000 ký tự ≈ 12000 token ước lượng + `max_tokens` ≤ 16384) |
| `AI_LLM_CONTEXT_TOKENS` | `ai-service/.env` | 16384 | Context (token) của model, phải bằng `OLLAMA_CONTEXT_LENGTH`/`num_ctx`: service `ollama` trong compose đặt 16384; Ollama cài trực tiếp phải chạy `OLLAMA_CONTEXT_LENGTH=16384 ollama serve` (≥ 16384). Trước khi gọi, prompt có ước lượng `ký tự/3` token cộng `max_tokens` của lời gọi vượt giá trị này thì không gửi: 422 `VALIDATION_FAILED` (generate) hoặc bỏ qua repair. Sau khi gọi, nếu số prompt token provider báo cho thấy prompt bị cắt (tỉ lệ ký tự/token > 5,5, hoặc > 4,5 khi số token nằm sát một kích thước context, hoặc ≥ giá trị này) thì lời gọi được ghi kèm lỗi; mọi lời gọi GENERATE đều lỗi thì trả 502 `LLM_PROVIDER_ERROR` kèm các lời gọi trong `details.llm_calls` |

Trong test (`phpunit.xml`), các biến `DB_DATABASE=ait_test`, `AI_SERVICE_DRIVER=fake`, `TARGET_CLIENT_DRIVER=fake`, `BENCHMARK_SANDBOX_DRIVER=fake` bị ép giá trị.

---

## 8. Màn hình (UI tiếng Việt)

| # | Màn hình | Actor | Sprint |
|---|---|---|---|
| M00 | `/` chuyển khách tới M01 và người đã đăng nhập tới M02 (không có trang chủ riêng) | Khách | S14 |
| M01 | Đăng nhập | Khách | S1 |
| M02 | Tổng quan: TESTER thấy số liệu cá nhân (số project, lần chạy gần đây, trạng thái hệ thống); ADMIN thấy "Tổng quan quản trị" toàn hệ thống (người dùng hoạt động/bị khóa, dự án, lần chạy 7 ngày, dịch vụ, benchmark/bug), `/admin` chuyển về đây (S14-04) | ADMIN, TESTER | S1, S14 |
| M03 | Quản lý người dùng | ADMIN | S1 |
| M04 | Cấu hình: allowlist target (CRUD), xem thông tin LLM provider/model hệ thống (chỉ đọc) | ADMIN | S1 |
| M05 | Danh sách / tạo / sửa project | TESTER | S1 |
| M06 | Nhập OpenAPI (upload file / dán nội dung), lịch sử phiên bản spec | TESTER | S2 |
| M07 | Danh sách endpoint và chi tiết schema | TESTER | S2 |
| M08 | Business rules của project | TESTER | S7 |
| M09 | Test suite: danh sách test case, lọc theo nguồn/kỹ thuật/trạng thái | TESTER | S3 |
| M10 | Chi tiết test case: xem/sửa DSL, lỗi validation, log LLM liên quan | TESTER | S3, S8 |
| M11 | Sinh test: chọn endpoint, chiến lược RULE/LLM/HYBRID, tham số; tiến độ và thống kê job | TESTER | S3, S6 |
| M12 | Chạy test: chọn suite và target; tiến độ; kết quả từng case (request/response/assertion) | TESTER | S4 |
| M13 | Báo cáo lần chạy và xuất JSON/CSV/HTML | TESTER | S8 |
| M14 | Benchmark và bug ground truth; vai trò xác thực và business rules của ground truth (chỉ đọc, không hiện token, ADR-0006) | ADMIN | S9 |
| M15 | Thực nghiệm: cấu hình, chạy, bảng chỉ số so sánh RULE/LLM/HYBRID | TESTER | S9 |

Quy ước UI: Blade component trong `resources/views/components/`, Alpine cho tương tác, gọi `/api/v1` bằng fetch, hiển thị `error.message`. Mọi chuỗi UI viết tiếng Việt có dấu. Phần lõi `<head>` (charset, viewport, title, favicon, font, Vite assets có nonce CSP) nằm ở `layouts/partials/head.blade.php`, dùng chung cho mọi layout. Trang đăng nhập M01 dùng layout trống `layouts/auth.blade.php` (chỉ khung đăng nhập giữa nền và logo); mọi trang sau đăng nhập dùng khung theo tác nhân bên dưới.

**Layout theo tác nhân (S14-04):** hai khung giao diện dùng chung `layouts/base.blade.php` (`<head>`, skip link, sidebar responsive: cố định từ `xl`, ngăn kéo có nút menu và giữ focus bên dưới, thanh trên có menu tài khoản, flash, footer): `layouts/admin.blade.php` (nhấn indigo, "Khu vực quản trị", nhóm Quản trị M02/M03/M04/M14/M15 và nhóm "Không gian kiểm thử") và `layouts/tester.blade.php` (nhấn teal, "Không gian kiểm thử", không có liên kết quản trị). Màn hình `admin/*` extend `layouts.admin`; mọi màn hình dùng chung extend `layouts.app`, tự chọn khung theo vai trò người đăng nhập (`App\Support\RoleLayout`). Trong một dự án, sidebar hiện liên kết ngữ cảnh M06/M07, M08, M09, M12 (`App\Support\NavigationContext`).

---

## 9. Checklist bảo mật (kiểm trong mọi PR liên quan)

- [ ] Không có secret trong code, log, fixture; gitleaks xanh.
- [ ] Mọi route trừ `/` (chỉ chuyển hướng, lượt khách chưa có cookie session/ghi nhớ thì không mở session), đăng nhập, `/up` và `GET /api/v1/health` (công khai theo §6) đều yêu cầu auth; route `storage/{path}` của framework chỉ nhận URL có chữ ký; file tĩnh của `public/` (`/build/*`, `/robots.txt`, `/favicon.ico`, route `{path}` của `PublicAssetController`, chỉ đuôi trong danh sách cho phép, không session) công khai; mọi thao tác trên tài nguyên đều qua Policy (TESTER chỉ thấy project của mình).
- [ ] Input đi qua FormRequest; upload spec giới hạn ≤ 5 MB, chỉ nhận `.json/.yaml/.yml`, parse YAML an toàn (không object tag).
- [ ] Executor: kiểm tra allowlist **sau khi** resolve host, cấm redirect, timeout bắt buộc, giới hạn kích thước response (64 KB khi lưu).
- [ ] Nội dung từ LLM là dữ liệu không tin cậy: chỉ parse JSON, không `eval`, render bằng `{{ }}` (escape), không dùng `{!! !!}` cho dữ liệu LLM hay response target.
- [ ] Không gửi API key LLM ra trình duyệt hoặc vào `llm_call_logs`.
- [ ] CSRF bật cho form web; cookie session `HttpOnly`, `SameSite=Lax`.
- [ ] Rate limit cho đăng nhập và cho endpoint tạo generation job hoặc run.
- [ ] Mật khẩu hash bằng bcrypt; ADMIN không xem được mật khẩu.
- [ ] Log không chứa header `Authorization` của request tới target (che bằng `***`).
- [ ] Showcase demo (`DEMO_SHOWCASE_ENABLED=true`) chỉ bật trên site demo: tài khoản demo công khai không được bảo vệ dữ liệu thật hay bí mật (ADR-0007 bổ sung 2026-10-13).

---

## 10. Pipeline sinh, kiểm tra và thực thi (tóm tắt nghiệp vụ lõi)

1. **Parse**: OpenAPI → resolve `$ref` → chuẩn hóa endpoint (method, path, params, body schema, responses).
2. **Sinh**:
   - RULE: theo kỹ thuật `HAPPY_PATH, MISSING_REQUIRED, TYPE_MISMATCH, BOUNDARY, EQUIVALENCE, INVALID_INPUT`. Endpoint có `security` hiệu lực bắt buộc credential (không có requirement rỗng `{}`) chỉ sinh một case `AUTH`: request hợp lệ không kèm credential, assert 401 (bỏ qua nếu spec không khai báo 401), vì pipeline chưa có nguồn credential (S10-01).
   - LLM: prompt có cấu trúc gồm endpoint, schema và business rules, yêu cầu JSON theo DSL.
   - HYBRID: lấy case RULE làm seed, LLM bổ sung `BUSINESS_RULE, SEQUENCE, AUTH` và các biên khó.
3. **Validate**: JSON Schema DSL, rồi kiểm tra ngữ nghĩa (endpoint/field/status có trong spec, biến `{{x}}` đã được extract trước khi dùng).
4. **Repair** (chỉ LLM): gửi lỗi validation cho LLM, tối đa N lần, rồi kết luận `INVALID`.
5. **Dedup**: fingerprint = sha256 của DSL chuẩn hóa (bỏ title/description, sắp key), trùng với case `VALID`/`APPROVED` của suite hoặc với case trước đó trong cùng lô thì `DUPLICATE` (case `DUPLICATE`, `INVALID`, `ARCHIVED` không tính là đã có; ai-service chỉ dedup trong lô và với seed case HYBRID, web dedup lại lần cuối).
6. **Execute**: theo từng step, nội suy biến, gửi request qua `TargetHttpClient`. Lượt chạy của thực nghiệm có fixture xác thực, và lượt chạy M12 có host target là benchmark của nền tảng (fixture đọc từ `ground-truth.yaml` của benchmark đó, bổ sung 2026-10-14), thì định nghĩa biến `auth_token_<role>` và thêm header `Authorization` mặc định cho step gọi operation có security (trừ case AUTH hoặc case tự đặt header), ADR-0006.
7. **Assert**: `status, status_in, json_schema, json_path, header, response_time` cho ra verdict.

---

## 11. Benchmark và chỉ số đánh giá

- Tối thiểu **3 benchmark API**, tổng **≥ 30 seeded bug** phân loại: validation thiếu, sai mã trạng thái, sai schema response, lỗi biên, vi phạm business rule, lỗi chuỗi nhiều bước, lỗi phân quyền.
- Mỗi cấu hình (chiến lược × benchmark × model) chạy **≥ 3 lần lặp** với seed ghi lại.

| Chỉ số | Công thức |
|---|---|
| Validity Rate | số case VALID / số case sinh ra |
| Executable Rate | số case chạy không ERROR trên bản sạch / số case VALID |
| False Positive Rate | số case FAIL trên bản sạch / số case chạy được |
| Bug Detection Rate | số bug phát hiện (D14) / tổng seeded bug |
| Mutation Score | số biến thể bug bị "giết" / tổng biến thể |
| Endpoint Coverage | số endpoint có ≥1 case VALID / tổng endpoint |
| Status Code Coverage | số cặp (endpoint, status) được assert / số cặp có trong spec |
| Redundancy | số case DUPLICATE / số case sinh ra |
| Thời gian, Token | thời gian sinh + chạy; tổng prompt/completion tokens |

---
