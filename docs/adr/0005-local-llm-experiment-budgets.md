# ADR-0005: Giao thức thực nghiệm với LLM local: sinh theo từng endpoint và ngân sách thời gian

- Trạng thái: Chấp nhận (Duyệt bởi CODEOWNER (chat) ngày 2026-10-12)
- Ngày: 2026-10-11
- Liên quan: D09, D10, D14 trong docs/DESIGN.md §2; docs/DESIGN.md §7, §11, §14; `docs/architecture/experiment-design.md` §4.2; ADR-0003; task S11-02, S12-02

> Ghi chú đánh số: lời giao task nêu tên `0004-local-llm-experiment-budgets.md`, nhưng số 0004 đã dùng cho
> `0004-ai-service-health-llm-defaults.md` (S10-01) trên `main`, nên ADR này lấy số 0005.

## Bối cảnh
`docs/architecture/experiment-design.md` §4.2 chốt tham số sinh test: `temperature = 0.2`,
`max_repair_attempts = 2`, `timeout = 120s` cho mỗi request gọi LLM. Ngầm định khi đó: một lần sinh là
**một lời gọi LLM cho mọi endpoint** của benchmark, và web chờ ai-service khoảng 120 giây
(`AI_SERVICE_TIMEOUT`, ngân sách `AI_GENERATE_BUDGET_SECONDS` = 100 giây).

Đo trên máy của nhóm (MacBook Apple Silicon, Ollama native, `qwen2.5-coder:7b`, ~18 token/giây):

- simple-store: prompt ~6 000 token, câu trả lời ~6 000 token, tức ~6 phút cho một lời gọi. Mọi lần sinh
  LLM/HYBRID bị cắt ở 100–120 giây (`LLM_PROVIDER_ERROR: timed out`), thực nghiệm kết thúc FAILED.
- Context mặc định của Ollama (2k–4k token) cắt mất đầu prompt (system prompt) mà không báo lỗi.
- Câu trả lời dài (10 case mỗi endpoint, mọi endpoint trong một lần) hay bị cắt giữa JSON, phải repair nhiều.

PR #116 (S11-02) đã đổi ai-service và web để chạy được với model local: mỗi endpoint một lời gọi
GENERATE nhỏ có `max_tokens`, `AI_LLM_CASES_PER_CALL`, chuỗi thời gian thống nhất và context 16384.
Các thay đổi này làm khác đi điều kiện đo của §4.2, nên cần ghi lại thành giao thức chính thức để
`make experiment` (S12-02) chạy đúng mặc định và báo cáo nêu rõ sai khác.

## Các phương án đã xem xét
1. **Giữ nguyên §4.2 (120 giây mỗi lời gọi, ngân sách 100 giây)**. Ưu: không đổi tài liệu. Nhược: chỉ máy
   có GPU rời (≥ ~80 token/giây) chạy được; trên máy của nhóm mọi thực nghiệm LLM/HYBRID FAILED, không có
   số liệu để so sánh RULE/LLM/HYBRID.
2. **Giữ một lời gọi cho mọi endpoint nhưng nâng timeout lên 900–1200 giây** (cách tạm của bản nháp S12-02).
   Ưu: ít thay đổi code. Nhược: một lời gọi ~6–20 phút, dễ cắt JSON, prompt lớn dễ vượt context; một lỗi
   làm mất toàn bộ endpoint; khó so sánh giữa các benchmark có số endpoint khác nhau.
3. **Sinh theo từng endpoint với số case mỗi lời gọi nhỏ, `max_tokens` giới hạn, ngân sách thời gian theo
   chuỗi, context ≥ 16384** (S11-02). Ưu: mỗi lời gọi ~60–90 giây, lỗi chỉ ảnh hưởng một endpoint, hết ngân
   sách thì trả kết quả một phần (`PARTIAL`) có log; chạy được trên laptop. Nhược: LLM sinh ít case hơn mỗi
   endpoint so với RULE; tổng thời gian một lần sinh dài hơn §4.2.

## Quyết định
Chọn phương án 3. Giao thức thực nghiệm (thay thế dòng `timeout = 120s` của §4.2) là:

| Tham số | Giá trị mặc định | Biến cấu hình |
|---|---|---|
| Đơn vị gọi LLM | một lời gọi GENERATE cho mỗi endpoint, theo thứ tự; repair dùng thời gian còn lại | — |
| Số case yêu cầu mỗi lời gọi | 3 (= min(`max_cases_per_endpoint`, `AI_LLM_CASES_PER_CALL`)) | `AI_LLM_CASES_PER_CALL` / `make experiment CASES_PER_CALL=` |
| `max_tokens` | GENERATE = số case × 400 + 256 (1456); REPAIR = 2 × 400 + 256 (1056); tối đa nửa context | `AI_LLM_OUTPUT_TOKENS_PER_CASE` |
| Timeout một lời gọi LLM | 300 giây | `AI_LLM_TIMEOUT_SECONDS` / `LLM_TIMEOUT` |
| Ngân sách một `POST /v1/generate` | 1320 giây; hết thì bỏ endpoint/repair còn lại, job `PARTIAL` | `AI_GENERATE_BUDGET_SECONDS` / `GENERATE_BUDGET` |
| Web chờ ai-service | ngân sách + 60 = 1380 giây; nhỏ hơn thì web từ chối sinh LLM/HYBRID | `AI_SERVICE_TIMEOUT` |
| Chuỗi thời gian | 300 < 1320 ≤ 1380 − 60 < job 1500 ≤ worker 1800 < `retry_after` 1900 | — |
| Context của Ollama | ≥ 16384 token (`OLLAMA_CONTEXT_LENGTH=16384 ollama serve`), bằng `AI_LLM_CONTEXT_TOKENS`; prompt ≤ 36000 ký tự | `AI_LLM_CONTEXT_TOKENS`, `AI_MAX_PROMPT_CHARS` |
| `temperature`, `max_repair_attempts`, seed | 0.2, 2, 42/1024/2026 (không đổi) | như §4.2 |

**Trần số case hiệu dụng mỗi endpoint** (trước repair/dedup), với `max_cases_per_endpoint = 10` do
`RunExperimentAction` đặt:

- RULE: ≤ 10 case mỗi endpoint.
- LLM: mỗi endpoint được **yêu cầu** min(10, 3) = 3 case trong một lời gọi; nếu model trả nhiều hơn,
  ai-service vẫn giữ tối đa 10 case mỗi endpoint (`max_cases_per_endpoint`), nên trần cứng là 10 nhưng
  số case điển hình là ~3 mỗi endpoint.
- HYBRID: case RULE làm seed (≤ 10) + phần LLM bổ sung như trên (yêu cầu 3, trần 10) mỗi endpoint, tập
  trung kỹ thuật BUSINESS_RULE, SEQUENCE, AUTH.

`make experiment` dùng đúng các mặc định trên (`docker/docker-compose.experiment-llm.yml`,
`docker/experiment/run-experiment.sh`). Giá trị thực tế của mỗi lần chạy (timeout, ngân sách, số case
mỗi lời gọi, `max_tokens`, trần case từng chiến lược, context) được ghi trong `environment.txt` của
thư mục kết quả; giá trị khác giao thức được cảnh báo khi chạy.

## Hệ quả
- Tích cực: thực nghiệm LLM/HYBRID chạy được trên máy không có GPU rời; lỗi hoặc hết thời gian của một
  endpoint không làm mất cả lần sinh; prompt không còn bị cắt im lặng; mọi lời gọi vẫn được lưu nguyên văn (D09).
- Tiêu cực / rủi ro:
  - LLM được yêu cầu 3 case mỗi endpoint so với tối đa 10 của RULE: số case không cân bằng, phải nêu khi so sánh
    Bug Detection Rate, Coverage và Redundancy giữa các chiến lược (báo cáo nên kèm số case VALID).
  - Thời gian sinh một lần lặp dài (tới 22 phút); benchmark nhiều endpoint có thể hết ngân sách và thiếu
    case ở các endpoint cuối (job `PARTIAL`), làm giảm Endpoint Coverage của LLM/HYBRID.
  - Kết quả phụ thuộc tốc độ máy: cùng ngân sách, máy chậm hơn có thể sinh ít endpoint hơn. Báo cáo phải
    nêu phần cứng và tốc độ token (từ `llm_call_logs`).
- Việc cần làm theo: cập nhật `docs/architecture/experiment-design.md` §4.2 trỏ tới ADR này (làm cùng PR);
  docs/DESIGN.md §7 và §14 ghi mặc định của `make experiment` (làm cùng PR). Đã duyệt: CODEOWNER (chat) ngày 2026-10-12.
