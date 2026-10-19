# Benchmark #1: Simple Store REST API

API sandbox mô phỏng hệ thống quản lý sản phẩm và danh mục (E-commerce / Store) xây dựng trên nền tảng **FastAPI**, tuân thủ chuẩn **OpenAPI 3.1.0**, phục vụ đánh giá các chiến lược sinh kiểm thử tự động (Rule-based, LLM-based, Hybrid).

Sandbox hỗ trợ 2 chế độ:
- **Clean Baseline**: Hoạt động chuẩn xác 100% theo đặc tả `openapi.yaml`.
- **Seeded Bugs**: Kích hoạt 10 lỗi cài sẵn (`BUG-001` đến `BUG-010`) để đối chứng với `ground-truth.yaml`.

---

## 1. Cấu trúc thư mục

```
benchmark/simple-store/
├── app/                  # Mã nguồn FastAPI
│   ├── config.py         # Cấu hình Settings và hàm is_bug_active()
│   ├── main.py           # Khởi tạo FastAPI app
│   ├── routes/           # Endpoints: products, categories, health
│   ├── schemas.py        # Pydantic models & validation logic
│   └── store.py          # In-memory storage sandbox
├── openapi.yaml          # Đặc tả OpenAPI 3.1.0 công bố cho Generator
├── ground-truth.yaml     # Danh sách 10 seeded bug & oracle assertion
├── tests/                # Test suite (26 baseline tests + 11 bug tests)
├── requirements.txt      # Python dependencies
└── pyproject.toml        # Cấu hình uv / pytest / ruff
```

---

## 2. Hướng dẫn chạy Sandbox

### Cách 1: Chạy trực tiếp bằng `uv` (Local Development)

```bash
cd benchmark/simple-store

# Tạo venv và cài dependencies
uv venv
uv pip install -r requirements.txt

# Khởi động server chế độ Clean Baseline (mặc định port 8000)
uv run uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

### Cách 2: Chạy qua Docker Compose

```bash
# Từ thư mục gốc dự án
make up
# hoặc
docker compose -f docker/docker-compose.yml --env-file .env up -d benchmark
```
API sandbox sẽ lắng nghe tại `http://localhost:8002` (theo port mapping trong `docker-compose.yml`).

---

## 3. Hướng dẫn bật / tắt Seeded Bug Toggles

Mọi bug toggle mặc định ở trạng thái **tắt** (`False`). Bạn có thể kích hoạt từng bug độc lập bằng một trong các cách sau:

### Cách A: Qua biến môi trường (Environment Variables)

Đặt biến `BUG_<XXX>=1` (hoặc `true`, `yes`, `on`):

```bash
# Bật BUG-001 và BUG-004 khi chạy uvicorn
BUG_001=1 BUG_004=1 uv run uvicorn app.main:app --port 8000

# Hoặc export trong terminal
export BUG_002=1
export BUG_007=1
```

Nếu chạy qua Docker, thêm biến môi trường vào service `benchmark` trong `docker/docker-compose.yml`:
```yaml
benchmark:
  environment:
    - BUG_001=1
    - BUG_005=1
```

### Cách B: Trong mã kiểm thử tự động (Python / Pytest)

```python
from app.config import settings

# Kích hoạt bug trong test
settings.bug_001 = True

# Hoặc dùng monkeypatch biến môi trường
monkeypatch.setenv("BUG_001", "1")
```

---

## 4. Bảng danh sách 10 Seeded Bugs (`ground-truth.yaml`)

| Mã Bug | Biến Env | Category | Endpoint | Mô tả lỗi |
|---|---|---|---|---|
| `BUG-001` | `BUG_001` | `VALIDATION_MISSING` | `POST /products` | Bỏ qua validate trường `name`, chấp nhận tạo sản phẩm không có tên |
| `BUG-002` | `BUG_002` | `BOUNDARY_ERROR` | `POST /products` | Cho phép `price < 0`, bỏ qua biên giá hợp lệ |
| `BUG-003` | `BUG_003` | `STATUS_CODE_WRONG` | `POST /products` | Trả về `200 OK` thay vì `201 Created` khi tạo mới thành công |
| `BUG-004` | `BUG_004` | `STATUS_CODE_WRONG` | `GET /products/{id}` | Trả về `200 OK` với body `{}` thay vì `404 Not Found` khi ID không tồn tại |
| `BUG-005` | `BUG_005` | `SCHEMA_MISMATCH` | `GET /products/{id}` | Không trả về trường bắt buộc `sku` trong response JSON |
| `BUG-006` | `BUG_006` | `BOUNDARY_ERROR` | `PUT /products/{id}` | Cho phép cập nhật `quantity > 100,000` (vượt ngưỡng biên tối đa) |
| `BUG-007` | `BUG_007` | `VALIDATION_MISSING` | `GET /products` | Tham số phân trang `limit <= 0` hoặc `> 100` gây crash nội bộ `500` |
| `BUG-008` | `BUG_008` | `BUSINESS_RULE_VIOLATION` | `POST /categories` | Cho phép tạo danh mục trùng `slug`, vi phạm quy tắc duy nhất |
| `BUG-009` | `BUG_009` | `STATUS_CODE_WRONG` | `DELETE /products/{id}` | Trả về `200 OK` có message body thay vì `204 No Content` |
| `BUG-010` | `BUG_010` | `VALIDATION_MISSING` | `GET /products/{id}` | Path ID dạng chuỗi chữ gây unhandled crash `500` thay vì `422` |

---

## 5. Fixture thực nghiệm (ADR-0006)

`ground-truth.yaml` khai báo `business_rules` `BR-SS-001`–`BR-SS-008`, mỗi operation nghiệp vụ (trừ `/health`) một
rule, gồm cả operation có và không có seeded bug: slug/SKU duy nhất, category phải tồn tại, phân trang, lọc theo
`category_id`, cập nhật từng phần, đọc/xóa tài nguyên không tồn tại trả 404. Đây là hành vi đúng đọc được từ spec
và README, không phải đáp án bug; rule chỉ nhắc path, mã HTTP và mã lỗi có trong `openapi.yaml`/README (kiểm bằng
`cd benchmark/tools && uv run python ground_truth_fixture.py`). Chỉ LLM/HYBRID đọc business rules (snapshot lúc tạo
thực nghiệm). Benchmark không có endpoint yêu cầu xác thực nên không khai báo `auth`.

## 6. Dữ liệu seed và `examples` trong `openapi.yaml` (S11-01)

Mỗi lần khởi động/reset, sandbox có category `1` (`books`), `2` (`electronics`) và product `1` (`BOOK-001`),
`2` (`TECH-001`). `openapi.yaml` khai báo `examples` khớp dữ liệu này: path `id` của category/product là `1`,
`category_id` (lọc và tạo product) là `1`, giá `19.99`, số lượng `10`; ví dụ slug (`home-appliances`,
`garden-tools`) không trùng slug đã seed. Bộ sinh RULE dùng `examples` để dựng request hợp lệ, nên request
"happy path" trỏ tới tài nguyên có thật thay vì một id đoán mò; riêng slug/SKU của thao tác tạo/cập nhật được RULE
sinh mới để không trùng. `PUT /products/{id}` chỉ đổi các trường gửi trong body: trường bỏ trống hoặc gửi `null` giữ
nguyên giá trị cũ. Test: `tests/test_spec_examples.py`.

## 7. Chạy Kiểm thử và Linter

```bash
# Kiểm tra linter chuẩn PEP 8 và format code
uv run ruff check .
uv run ruff format --check .

# Chạy toàn bộ 37 test cases (26 baseline + 11 seeded bug verification)
uv run pytest
```
