# ADR-0004: ai-service `GET /health` báo cáo model, temperature, seed mặc định cho M04

- Trạng thái: Chấp nhận (Duyệt bởi CODEOWNER (chat) ngày 2026-10-12)
- Ngày: 2026-10-11
- Liên quan: docs/DESIGN.md §1 (quyền ADMIN), §8 (M04), §9 (không lộ API key), D02, D09; `docs/contracts/ai-service.openapi.yaml` (`HealthResponse`)

## Bối cảnh
docs/DESIGN.md §1 và §8 yêu cầu M04 hiển thị (chỉ đọc) **LLM provider/model hệ thống**. Web không truy cập
`ai-service/.env` (D02: hai service tách biệt), còn `GET /health` của ai-service chỉ trả `status, provider,
version` với `additionalProperties: false`. Vì vậy M04 chỉ ghi "Mặc định của ai-service" thay vì tên model
thật (phát hiện của đợt rà soát S10-01 web-ui vòng 2).

## Các phương án đã xem xét
1. **Web đọc `AI_LLM_MODEL` từ `web/.env`**. Ưu: không đổi hợp đồng. Nhược: hai nguồn sự thật, dễ lệch với
   model ai-service thực sự dùng; trái nguyên tắc M04 hiển thị giá trị do ai-service báo cáo.
2. **Thêm endpoint mới `GET /v1/config` ở ai-service**. Nhược: thêm một tài nguyên chỉ để đọc ba giá trị.
3. **Bổ sung `model`, `temperature`, `seed` (không bí mật) vào `HealthResponse`**. Ưu: thay đổi cộng thêm,
   web gọi `/health` sẵn; không lộ `AI_LLM_API_KEY`.

## Quyết định
Chọn phương án 3. `HealthResponse` của ai-service có thêm `model` (string), `temperature` (number ≥ 0),
`seed` (integer ≥ 0), là thuộc tính tùy chọn trong hợp đồng để client cũ vẫn hợp lệ. Web đọc ba giá trị qua
`AiGeneratorClient::status()` (`AiServiceStatus`) và hiển thị ở M04 qua `DescribeLlmConfigurationAction`.
`GET /api/v1/health` công khai của web **không đổi** (`checks.ai_service` vẫn chỉ có `reachable, provider, version`).

## Hệ quả
- Tích cực: M04 đúng §8; một nguồn sự thật cho cấu hình LLM.
- Tiêu cực / rủi ro: `/health` của ai-service (mạng nội bộ Docker) cho biết model đang dùng; đây không phải bí mật.
  API key tuyệt đối không được thêm vào phản hồi (có test bảo vệ).
- Việc cần làm theo: cập nhật `docs/contracts/ai-service.openapi.yaml` (đã làm trong cùng PR).
