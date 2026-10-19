# Benchmark APIs

Các API sandbox do nhóm tự xây dựng (FastAPI, OpenAPI 3.x), có **lỗi cài sẵn (seeded bugs)** bật/tắt bằng biến môi trường, kèm **ground truth** để đánh giá khách quan các chiến lược sinh test.

Đã triển khai Benchmark #1 (`simple-store`) cùng 10 seeded bugs ở S1-06/S2-04,
Benchmark #2 (`ecommerce-order`) cùng 12 seeded bugs ở S5-04/S7-04, và
Benchmark #3 (`appointment-booking`) cùng 10 seeded bugs ở S8-03 (đạt mốc 32 bugs).
Xem docs/DESIGN.md §11 và `docs/architecture/experiment-design.md`.

Cấu trúc:

```
benchmark/
  <slug>/
    app/              # mã nguồn FastAPI
    openapi.yaml      # đặc tả công bố cho hệ thống
    ground-truth.yaml # danh sách BUG-xxx: endpoint, loại lỗi, toggle
    tests/            # test xác nhận từng bug thật sự gây sai khác hành vi
  results/            # kết quả `make experiment` theo thời điểm UTC (xem results/README.md)
    raw/              # đầu ra thô lớn (không commit; lưu ở release)
```

| Benchmark | Phạm vi hiện tại | Cổng host mặc định |
|---|---|---:|
| `simple-store` | CRUD, validation, 10 seeded bugs (BUG-001–010) | 8002 |
| `ecommerce-order` | Multi-step order workflow và RBAC, 12 seeded bugs (BUG-011–022) | 8003 |
| `appointment-booking` | Scheduling logic, quota, 24h window, 10 seeded bugs (BUG-023–032) | 8004 |

## Fixture thực nghiệm trong `ground-truth.yaml` (ADR-0006)

Ngoài `bugs`, mỗi `ground-truth.yaml` có thể khai báo `auth` (token synthetic công khai của sandbox theo vai
trò, vai trò mặc định, vai trò theo operation) và `business_rules` (hành vi đúng đọc được từ spec/README,
không nêu mã bug). Thực nghiệm snapshot hai khóa này lúc tạo; executor gửi credential mặc định như nhau cho
RULE/LLM/HYBRID, bộ sinh chỉ thấy tên vai trò và biến `{{auth_token_<role>}}`. Kiểm tra fixture (token đúng
với `app/auth.py`, operation có trong `openapi.yaml`, rule không nhắc bug):

```bash
cd benchmark/tools && uv run python ground_truth_fixture.py   # cũng chạy trong pytest của tools
```

## Hạ tầng thực nghiệm: sandbox một bug và reset dữ liệu (S12-02)

### Reset dữ liệu seed

Mỗi benchmark có endpoint nội bộ `POST /__admin/reset` (trả `200 {"status": "reset", "service": "<slug>"}`)
đưa dữ liệu in-memory về đúng bộ seed ban đầu (`experiment-design.md` §4.3.1). Endpoint này **không**
nằm trong `openapi.yaml` lẫn OpenAPI runtime (`include_in_schema=False`) nên generator không sinh test
nhắm vào nó và DslValidator sẽ từ chối nếu có case gọi tới.

### Một container cho mỗi seeded bug

Toggle `BUG_xxx` được đọc từ biến môi trường của tiến trình khi khởi động, nên mỗi biến thể bug là
một container riêng chỉ bật đúng một toggle. File `docker/docker-compose.experiment.yml` được **sinh tự động**
từ `benchmark/*/ground-truth.yaml` bởi `benchmark/tools/gen_variant_compose.py` (không sửa tay):

```bash
make experiment-compose   # sinh lại file sau khi đổi ground-truth.yaml (CI kiểm tra file luôn mới)
make up-experiment        # stack thường + 32 sandbox một bug (không mở cổng ra host, 128 MB / 0.25 CPU mỗi container)
                          # + docker-compose.experiment-llm.yml: LLM thật qua Ollama trên máy host, driver thật
make down-experiment
```

Quy ước hostname trong mạng Docker (cổng nội bộ luôn là `8000`):

| Biến thể | Hostname | Ví dụ base URL |
|---|---|---|
| Bản sạch | `<slug>` (network alias của service sạch `benchmark`, `benchmark-order`, `benchmark-booking`) | `http://simple-store:8000` |
| Một bug | `<slug>-<code viết thường>` = `<slug>-<toggle viết thường, "_" thành "-">` | `http://simple-store-bug-001:8000` |

Phía web dùng:

```dotenv
BENCHMARK_VARIANT_URL_TEMPLATE=http://{slug}-{code_lower}:8000
ALLOWED_TARGET_HOSTS=localhost,127.0.0.1,benchmark,<danh sách từ lệnh dưới>
```

`python benchmark/tools/gen_variant_compose.py --print-allowlist` in ra 35 hostname (3 bản sạch + 32 bug)
cần có trong allowlist (D07); `web/.env.example` đã chứa sẵn.

`base_url` trong mỗi `ground-truth.yaml` là `http://<slug>:8000` (alias mạng Docker của service sạch). Web dùng
nguyên giá trị này làm đích của biến thể sạch khi import benchmark (M14) và khi chạy thực nghiệm từ container
`web`/`worker`; `gen_variant_compose.py` và pytest của `benchmark/tools` từ chối giá trị khác. Khi gọi từ máy host
thì dùng cổng đã mở: `http://localhost:8002` (simple-store), `:8003` (ecommerce-order), `:8004` (appointment-booking).
Benchmark đã import trước đây với `http://localhost:800x` giữ nguyên `base_url` cũ khi import lại (không có
`PATCH /admin/benchmarks`), nên cần `make fresh` để import lại với giá trị mới.

Chỉ `ai-service` có `extra_hosts: host.docker.internal:host-gateway` để gọi được Ollama chạy trực tiếp trên
máy host (`AI_LLM_BASE_URL=http://host.docker.internal:11434/v1`); `web`/`worker` (gửi request tới target, D07)
không cần. Trên Linux mở Ollama trên cổng bridge Docker (`OLLAMA_HOST=172.17.0.1:11434`), không dùng `0.0.0.0`.
