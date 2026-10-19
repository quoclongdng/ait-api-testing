# ADR-0001: Kiến trúc lai Laravel (Web + Test Engine) và Python (ai-service)

- Trạng thái: Chấp nhận
- Ngày: 2026-10-05
- Liên quan: D01, D02, D03, D08 trong docs/DESIGN.md §2, task S0-01

## Bối cảnh
Đề cương yêu cầu: (1) nguyên mẫu Web quản lý project, OpenAPI, test suite, lần chạy và báo cáo; (2) Test Engine sinh test theo luật, thực thi và đánh giá assertion; (3) tích hợp LLM qua Python để sinh test có cấu trúc, validation, repair, dedup; (4) chạy được offline trên localhost/Docker, không phụ thuộc Internet; (5) thời gian 7 tháng với 6 thành viên, phần lớn quen PHP/Laravel và Python.

## Các phương án đã xem xét
1. **Toàn bộ Python (FastAPI + React)**: một ngôn ngữ. Nhược: phải tự dựng auth, admin, queue, ORM migration, tốn thời gian cho phần Web vốn không phải đóng góp nghiên cứu.
2. **NestJS + Next.js + Python**: hiện đại. Nhược: 3 ngôn ngữ/runtime, nhóm ít kinh nghiệm, nhiều cấu hình.
3. **Laravel 12 (Web + Test Engine) + Python FastAPI (ai-service)**: Laravel cho sẵn auth, policy, queue, migration, Blade; Python có hệ sinh thái LLM/JSON Schema tốt nhất.

## Quyết định
Chọn phương án 3.
- Laravel giữ dữ liệu (PostgreSQL), giao diện, OpenAPI parser, rule generator, Test Executor và Assertion Engine. Đây là phần **quyết định PASS/FAIL**, có tính tất định.
- ai-service (Python) không truy cập DB, không quyết định PASS/FAIL. Nó nhận endpoint đã chuẩn hóa, gọi LLM, kiểm tra cấu trúc, repair, dedup rồi trả về DSL cùng log thô qua HTTP theo `docs/contracts/ai-service.openapi.yaml`.
- Mọi phụ thuộc ngoài (ai-service, target API, LLM provider) đi qua interface và có bản Fake, chọn bằng biến môi trường.

## Hệ quả
- Tích cực: tách bạch "AI sinh" với "assertion quyết định" (D03), đúng tinh thần đề cương; hai nhóm có thể làm song song nhờ bản Fake; CI không gọi LLM thật.
- Tiêu cực: DSL bị validate ở hai nơi (Python và PHP), có nguy cơ lệch. Khắc phục: một JSON Schema duy nhất `docs/contracts/test-dsl.schema.json` cùng bộ ví dụ dùng chung làm fixture test cho cả hai bên.
- Việc cần làm theo: S0-02 (chốt DSL), S0-03 (chốt contract ai-service).
