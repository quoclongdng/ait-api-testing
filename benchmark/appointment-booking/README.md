# Benchmark #3: Appointment Booking API

API sandbox FastAPI cho task **S8-03**, tập trung vào các đặc trưng kỹ thuật và nghiệp vụ phức tạp:

- Ràng buộc nghiệp vụ logic cao: không trùng giờ/bác sĩ, giới hạn dời lịch trước 24h, hạn mức quota 20 bệnh nhân/ngày/bác sĩ, ngày đóng cửa/ngày lễ.
- Xử lý thời gian và định dạng ISO 8601 (`YYYY-MM-DDTHH:mm:ssZ`).
- Xác thực và phân quyền RBAC: `PATIENT`, `DOCTOR`, `ADMIN`, bảo vệ IDOR khi hủy lịch hẹn.
- Danh mục 10 lỗi cài sẵn (**Seeded Bugs** `BUG-023`–`BUG-032`) có cờ toggle độc lập và file `ground-truth.yaml` chuẩn hóa.

## Hợp đồng và dữ liệu cố định

- OpenAPI 3.1: `openapi.yaml`, gồm 8 endpoint nghiệp vụ và `/health`.
- Token synthetic công khai:
  - `patient-alice-token` — PATIENT (ID 1);
  - `patient-bob-token` — PATIENT (ID 2);
  - `doctor-carol-token` — DOCTOR (ID 101);
  - `admin-token` — ADMIN (ID 999).
- Giờ hoạt động và quy định:
  - Phòng khám đóng cửa vào Chủ Nhật và các ngày nghỉ lễ cố định (`2026-12-25`, `2026-01-01`).
  - Mỗi bác sĩ nhận tối đa 20 lượt khám trong 1 ngày.
  - Dời lịch (`PATCH /appointments/{id}`) yêu cầu thực hiện trước giờ hẹn ít nhất 24 giờ.
- Đồng hồ sandbox cố định ở `2026-10-10T08:00:00Z` (thứ Bảy): mọi quy tắc theo thời gian tính theo mốc này.
- Tham số `date` của `GET /doctors/{doctor_id}/slots` phải là ngày có thật trên lịch: giá trị khớp pattern
  `YYYY-MM-DD` nhưng không phải ngày (ví dụ `2026-02-30`) trả 422.
- Quyền: PATIENT chỉ xem, dời và hủy lịch hẹn của mình; DOCTOR và ADMIN xem và quản lý mọi lịch hẹn.
- Dữ liệu seed: khoa `1` (Cardiology), `2` (Dermatology), `3` (Pediatrics); bác sĩ `101` (khoa 1) và `102` (khoa 2);
  lịch hẹn `501` (alice, bác sĩ 101, `2026-10-15` 09:00) và `502` (bob, bác sĩ 101, `2026-10-10` 09:00). Lịch tạo qua
  API nhận id mới.
- Dữ liệu in-memory tự động reset về trạng thái chuẩn trước mỗi test.

## Fixture thực nghiệm: vai trò xác thực và business rules (ADR-0006)

`ground-truth.yaml` khai báo:

- `auth`: bearer token synthetic ở trên dưới dạng vai trò `patient` (alice, mặc định), `patient_bob`,
  `doctor`, `admin`; không có operation nào giới hạn theo vai trò (quyền sở hữu được kiểm tra trong nghiệp vụ).
- `business_rules` `BR-AB-001`–`BR-AB-010`: mỗi operation nghiệp vụ (trừ `/health`) một hoặc hai rule, gồm cả
  operation có và không có seeded bug; hành vi đúng đọc được từ spec/README (không phải đáp án bug), chỉ nhắc path,
  mã HTTP và mã lỗi có trong `openapi.yaml`/README. Chỉ LLM/HYBRID đọc business rules.

Khi tạo thực nghiệm, web lưu snapshot hai khóa này. Executor tự thêm
`Authorization: Bearer {{auth_token_<role>}}` cho step gọi endpoint có security (trừ case `AUTH` hoặc case
tự đặt header); RULE sinh thêm case `AUTH` (thiếu token, token sai → 401); LLM/HYBRID chỉ thấy tên vai trò và
dùng `{{auth_token_patient_bob}}` khi cần người dùng thứ hai. Kiểm tra fixture:
`cd benchmark/tools && uv run python ground_truth_fixture.py`.

## `examples` trong `openapi.yaml` (S11-01)

`openapi.yaml` khai báo `examples` khớp dữ liệu seed và vai trò mặc định (alice): `department_id` = `1`,
`doctor_id` = `101` (đặt lịch mới dùng bác sĩ `102`), ngày xem slot `2026-10-15`, `appointment_id` = `501`, ngày đặt
lịch `2026-10-16` (thứ Sáu), ngày dời lịch `2026-10-19` (thứ Hai), số điện thoại `+84901234567`. RULE dùng các giá
trị này để dựng request hợp lệ (trước đây không sinh được ngày khớp pattern nên POST/PATCH không có case hợp lệ).
Test: `tests/test_spec_examples.py`.

## Chạy bằng Docker Compose

Từ thư mục gốc dự án:

```bash
make up
curl http://localhost:8004/health
```

Service nội bộ là `http://benchmark-booking:8000`; cổng host mặc định là `8004`.

## Hướng dẫn bật / tắt Seeded Bug Toggles

Mọi bug toggle mặc định ở trạng thái **tắt** (`False`, chế độ Clean Baseline). Bạn có thể kích hoạt từng bug độc lập:

### Qua biến môi trường (Environment Variables)

Đặt biến `BUG_<XXX>=1` (hoặc `true`, `yes`, `on`):

```bash
# Kích hoạt BUG-023 và BUG-027 khi chạy uvicorn
BUG_023=1 BUG_027=1 uvicorn app.main:app --port 8000
```

### Trong mã kiểm thử tự động (Python / Pytest)

```python
from app.config import settings

# Kích hoạt bug trong test
settings.bug_023 = True
```

## Bảng danh mục 10 Seeded Bugs (`ground-truth.yaml`)

| Mã Bug | Biến Env | Category | Method & Path | Mô tả lỗi |
|---|---|---|---|---|
| `BUG-023` | `BUG_023` | `BOUNDARY_ERROR` | `POST /appointments` | Cho phép đặt lịch vào ngày trong quá khứ (`appointment_date < today`) |
| `BUG-024` | `BUG_024` | `BUSINESS_RULE_VIOLATION` | `POST /appointments` | Cho phép 2 lịch hẹn trùng bác sĩ và trùng khung giờ (Double booking) |
| `BUG-025` | `BUG_025` | `BUSINESS_RULE_VIOLATION` | `POST /appointments` | Đặt lịch vào ngày nghỉ/ngày lễ của phòng khám (Chủ Nhật) |
| `BUG-026` | `BUG_026` | `STATUS_CODE_WRONG` | `POST /appointments` | Trùng lịch trả HTTP `500` thay vì `409 Conflict` |
| `BUG-027` | `BUG_027` | `BUSINESS_RULE_VIOLATION` | `PATCH /appointments/{appointment_id}` | Cho phép dời lịch khi thời gian còn lại trước giờ hẹn < 24 giờ |
| `BUG-028` | `BUG_028` | `BUSINESS_RULE_VIOLATION` | `POST /appointments` | Cho phép số lượng lịch hẹn của 1 bác sĩ trong ngày vượt quota (tối đa 20) |
| `BUG-029` | `BUG_029` | `SCHEMA_MISMATCH` | `GET /doctors/{doctor_id}/slots` | Slot thời gian trả về sai định dạng ISO 8601 |
| `BUG-030` | `BUG_030` | `AUTH_ERROR` | `DELETE /appointments/{appointment_id}` | Bệnh nhân này hủy lịch hẹn của bệnh nhân khác không cần quyền (IDOR) |
| `BUG-031` | `BUG_031` | `STATUS_CODE_WRONG` | `GET /departments/{department_id}` | ID phòng ban không hợp lệ trả `400` với content-type `text/html` |
| `BUG-032` | `BUG_032` | `VALIDATION_MISSING` | `POST /appointments` | Không kiểm tra định dạng số điện thoại liên hệ của bệnh nhân |

## Kiểm thử và lint

```bash
docker compose -f docker/docker-compose.yml --env-file .env exec -T benchmark-booking pytest
docker compose -f docker/docker-compose.yml --env-file .env exec -T benchmark-booking ruff check .
docker compose -f docker/docker-compose.yml --env-file .env exec -T benchmark-booking ruff format --check .
```
