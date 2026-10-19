# Benchmark #2: E-commerce Order API

API sandbox FastAPI cho task **S5-04** và **S7-04**, tập trung vào hai năng lực mà benchmark
`simple-store` chưa bao phủ:

- Chuỗi nhiều bước: thêm giỏ hàng → checkout → đọc đơn → thanh toán;
- Xác thực và phân quyền: bearer token, vai trò `CUSTOMER`/`ADMIN`, kiểm tra chủ sở hữu.
- Danh mục 12 lỗi cài sẵn (**Seeded Bugs** `BUG-011`–`BUG-022`) có cờ toggle độc lập và file `ground-truth.yaml` chuẩn hóa (Task **S7-04**).

## Hợp đồng và dữ liệu cố định

- OpenAPI 3.1: `openapi.yaml`, gồm 12 operation nghiệp vụ và `/health`.
- Token synthetic công khai, chỉ dùng trong sandbox:
  - `customer-alice-token` — CUSTOMER, cart `101`;
  - `customer-bob-token` — CUSTOMER, cart `102`;
  - `admin-token` — ADMIN.
- Coupon hợp lệ: `SAVE10`, giảm 10% khi giỏ hàng đạt tối thiểu 100.
- Dữ liệu seed: sản phẩm `1` (tồn kho 5), `2` (100), `3` (50); giỏ `101` (alice) và `102` (bob) đều rỗng; đơn `901`
  (`PENDING`, của bob) và `902` (`SHIPPED`, của alice). Đơn/item tạo qua API nhận id mới.
- Đơn `PENDING` không giữ tồn kho. Khi thanh toán, API kiểm tra lại toàn bộ mặt hàng
  rồi trừ tồn kho nguyên tử. Một khóa reentrant dùng chung bảo vệ mọi thao tác đọc–ghi
  cart/order/stock; hủy đơn đã thanh toán sẽ hoàn lại tồn kho.
- Mỗi lần khởi động và mỗi test đều reset về cùng dữ liệu in-memory.

## Fixture thực nghiệm: vai trò xác thực và business rules (ADR-0006)

`ground-truth.yaml` khai báo:

- `auth`: bearer token synthetic ở trên dưới dạng vai trò `customer` (alice, mặc định), `customer_bob`,
  `admin`, cùng vai trò theo operation lấy từ tài liệu spec: `GET /admin/orders` dùng `admin` và cấm
  `customer`; các thao tác giỏ hàng/đơn của khách cấm `admin`.
- `business_rules` `BR-EO-001`–`BR-EO-012`: mỗi operation nghiệp vụ (trừ `/health`) một rule, gồm cả operation có
  và không có seeded bug; hành vi đúng đọc được từ spec/README (không phải đáp án bug), chỉ nhắc path, mã HTTP và
  mã lỗi có trong `openapi.yaml`/README. Chỉ LLM/HYBRID đọc business rules.

Khi tạo thực nghiệm, web lưu snapshot hai khóa này. Executor tự thêm
`Authorization: Bearer {{auth_token_<role>}}` cho step gọi endpoint có security (trừ case `AUTH` hoặc case
tự đặt header); RULE sinh thêm case `AUTH` (thiếu token, token sai → 401; vai trò bị cấm → 403); LLM/HYBRID chỉ
thấy tên vai trò. Kiểm tra fixture: `cd benchmark/tools && uv run python ground_truth_fixture.py`.

## `examples` trong `openapi.yaml` (S11-01)

`openapi.yaml` khai báo `examples` khớp dữ liệu seed và vai trò mặc định (alice): `product_id` = `2`, số lượng `1`
(trong tồn kho), `cart_id` = `101`, `order_id` = `902`, coupon `SAVE10`. RULE dùng các giá trị này để dựng request
hợp lệ; `code` của `POST /coupons/apply` là phép tra cứu (không tạo tài nguyên) nên RULE dùng đúng `SAVE10` (S11-01).
Giới hạn còn lại (dữ liệu seed không có nên không sửa bằng example): không có cart item seed cho
`DELETE /cart/items/{item_id}`, alice không có đơn `PENDING` seed để thanh toán/hủy, giỏ seed rỗng nên checkout và
coupon của một case đơn bước không đạt điều kiện (409). Thực nghiệm reset sandbox trước từng test case (ADR-0006,
bổ sung 2026-10-12), nên kết quả không phụ thuộc thứ tự các case. Test: `tests/test_spec_examples.py`.

## Chạy bằng Docker Compose

Từ thư mục gốc:

```bash
make up
curl http://localhost:8003/health
```

Service nội bộ là `http://benchmark-order:8000`; cổng host mặc định là `8003`.

## Hướng dẫn bật / tắt Seeded Bug Toggles

Mọi bug toggle mặc định ở trạng thái **tắt** (`False`, chế độ Clean Baseline). Bạn có thể kích hoạt từng bug độc lập:

### Qua biến môi trường (Environment Variables)

Đặt biến `BUG_<XXX>=1` (hoặc `true`, `yes`, `on`):

```bash
# Kích hoạt BUG-011 và BUG-014 khi chạy uvicorn
BUG_011=1 BUG_014=1 uvicorn app.main:app --port 8000
```

### Trong mã kiểm thử tự động (Python / Pytest)

```python
from app.config import settings

# Kích hoạt bug trong test
settings.bug_011 = True
```

## Bảng danh mục 12 Seeded Bugs (`ground-truth.yaml`)

| Mã Bug | Biến Env | Category | Method & Path | Mô tả lỗi |
|---|---|---|---|---|
| `BUG-011` | `BUG_011` | `BOUNDARY_ERROR` | `POST /cart/items` | Cho phép thêm sản phẩm vào giỏ với số lượng `quantity = 0` |
| `BUG-012` | `BUG_012` | `BUSINESS_RULE_VIOLATION` | `POST /cart/items` | Cho phép thêm số lượng vượt quá tồn kho thực tế của sản phẩm |
| `BUG-013` | `BUG_013` | `SEQUENCE_ERROR` | `POST /checkout` | Cho phép checkout một giỏ hàng rỗng (chưa có item nào) |
| `BUG-014` | `BUG_014` | `STATUS_CODE_WRONG` | `POST /checkout` | Checkout trả về `200 OK` thay vì `201` và thiếu header `Location` |
| `BUG-015` | `BUG_015` | `SEQUENCE_ERROR` | `POST /orders/{order_id}/pay` | Cho phép gọi thanh toán 2 lần cho cùng một đơn hàng (Double payment) |
| `BUG-016` | `BUG_016` | `BUSINESS_RULE_VIOLATION` | `POST /orders/{order_id}/cancel` | Cho phép hủy đơn hàng khi trạng thái đã là `SHIPPED` |
| `BUG-017` | `BUG_017` | `AUTH_ERROR` | `GET /admin/orders` | Người dùng thường (role `CUSTOMER`) gọi được endpoint của `ADMIN` |
| `BUG-018` | `BUG_018` | `AUTH_ERROR` | `GET /orders/{order_id}` | Khách hàng A xem được thông tin đơn hàng của khách hàng B (IDOR) |
| `BUG-019` | `BUG_019` | `SCHEMA_MISMATCH` | `POST /orders` | Response tạo đơn hàng trả về `total_amount` dạng string thay vì number |
| `BUG-020` | `BUG_020` | `BUSINESS_RULE_VIOLATION` | `POST /coupons/apply` | Áp dụng mã giảm giá khi tổng tiền đơn hàng chưa đạt mức tối thiểu |
| `BUG-021` | `BUG_021` | `STATUS_CODE_WRONG` | `DELETE /cart/items/{item_id}` | Xóa item không có trong cart trả `500` thay vì `404` |
| `BUG-022` | `BUG_022` | `SCHEMA_MISMATCH` | `GET /cart` | Response thiếu mảng `items` khi giỏ hàng trống (trả `null` thay vì `[]`) |

## Kiểm thử và lint

```bash
docker compose -f docker/docker-compose.yml --env-file .env exec -T benchmark-order pytest
docker compose -f docker/docker-compose.yml --env-file .env exec -T benchmark-order ruff check .
docker compose -f docker/docker-compose.yml --env-file .env exec -T benchmark-order ruff format --check .
```
