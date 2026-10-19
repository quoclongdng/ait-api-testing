# ADR-0006: Fixture xác thực và business rules của benchmark cho thực nghiệm (snapshot, tiêm credential, truy vết job sinh)

- Trạng thái: Chấp nhận (Duyệt bởi CODEOWNER (chat) ngày 2026-10-12)
- Ngày: 2026-10-12
- Liên quan: D03, D04, D05, D07, D13, D14 trong docs/DESIGN.md §2; docs/DESIGN.md §5, §6, §8 (M14), §10, §11;
  ADR-0002 §4, ADR-0003, ADR-0005; `docs/architecture/experiment-design.md`; task S11-02

## Bối cảnh
Đợt rà soát S10-01 vòng 3 để lại ba vấn đề làm sai lệch so sánh RULE/LLM/HYBRID (S11-02):

1. **Không có credential.** `ecommerce-order` và `appointment-booking` bảo vệ phần lớn endpoint bằng bearer
   token (`components.securitySchemes.bearerAuth`). Không chiến lược nào có token hợp lệ: trước PR #123 RULE
   nhận 401 và FAIL trên bản sạch (49/54 case ở ecommerce-order, 24/26 ở appointment-booking); sau PR #123
   RULE chỉ còn sinh case "thiếu credential → 401" cho endpoint có security, tức không kiểm thử được hành
   vi nghiệp vụ sau xác thực. Prompt LLM/HYBRID cũng không có token nào sandbox chấp nhận. False Positive
   Rate và Bug Detection Rate của hai benchmark này vì thế không phản ánh năng lực sinh test.
2. **Business rules không được snapshot.** LLM/HYBRID đọc business rules trực tiếp từ project dùng chung
   "Thực nghiệm: <benchmark>" tại thời điểm sinh; ai đó sửa rule giữa các lần lặp hoặc giữa hai thực nghiệm
   là kết quả không tái lập được, và bản xuất không cho biết rule nào đã được dùng.
3. **Không truy vết được mọi job sinh.** Lần lặp HYBRID có hai job (RULE làm seed, rồi job HYBRID gọi LLM),
   nhưng `ExperimentRepetition` và bản xuất JSON (`ExperimentExportItem`) chỉ có `generation_job_id` (job
   cuối); `generation_job_ids` chỉ nằm trong `repetitions_data` nội bộ và CSV.

Ràng buộc: giữ nguyên D03 (PASS/FAIL do AssertionEngine), D04 (mọi case qua validate + dedup), D05 (Test
DSL v1.0 không đổi hình dạng), D07 (allowlist), D14 (định nghĩa phát hiện bug). Ba chiến lược phải được đối
xử công bằng: cùng nguồn credential, cùng quy tắc gửi credential.

## Các phương án đã xem xét
1. **Đưa token thật vào prompt/business rules dạng văn bản** để LLM chép vào header. Ưu: không đổi code
   executor. Nhược: chỉ giúp LLM/HYBRID (RULE vẫn 401), token nằm rải rác trong DSL đã lưu và trong
   `llm_call_logs`; LLM hay quên header nên bản sạch vẫn FAIL vì 401; không công bằng.
2. **Mở rộng DSL (ví dụ trường `auth: {role}` trong step).** Ưu: rõ nghĩa. Nhược: đổi DSL v1.0 (D05, cần
   ADR riêng, sửa schema chung PHP/Python, fixture S0-02 và mọi validator).
3. **Fixture xác thực khai báo trong `ground-truth.yaml`, snapshot vào thực nghiệm, executor tiêm header
   mặc định và giải biến `{{auth_token_<role>}}`** (chọn). Dùng tính năng sẵn có của DSL (header + biến
   `{{var}}`), không đổi hình dạng DSL; RULE, LLM, HYBRID dùng chung một quy tắc.
4. **Lưu credential theo project (bảng mới hoặc cột mới).** Nhược: đổi DB schema; project thực nghiệm là
   không gian dùng chung nên vẫn không giải quyết snapshot.

## Quyết định
Chọn phương án 3, cụ thể như sau.

### 1. Fixture trong `benchmark/<slug>/ground-truth.yaml` (bổ sung D13)
Hai khóa mới, tùy chọn:

```yaml
auth:                       # chỉ khi benchmark có endpoint yêu cầu security
  scheme: bearer            # MVP chỉ hỗ trợ bearer: header Authorization: Bearer <token>
  default_role: customer    # vai trò dùng khi case không tự đặt Authorization
  roles:                    # tên vai trò: ^[a-z][a-z0-9_]{0,30}$
    customer:   { token: "customer-alice-token", description: "CUSTOMER alice (user 1, cart 101)" }
    customer_bob: { token: "customer-bob-token", description: "CUSTOMER bob (user 2) để kiểm tra quyền sở hữu" }
    admin:      { token: "admin-token", description: "ADMIN" }
  operations:               # tùy chọn: vai trò riêng của một operation, lấy từ tài liệu spec
    - { method: GET, path: /admin/orders, role: admin, forbidden_roles: [customer] }
business_rules:             # quy tắc nghiệp vụ người kiểm thử biết được từ spec/README, KHÔNG phải đáp án bug
  - { code: BR-EO-001, method: POST, path: /cart/items, description: "..." }
```

- Token là **token synthetic công khai của sandbox** (đã công bố trong README và `app/auth.py` của
  benchmark), không phải secret; ground truth chỉ được chứa loại token này. Test `benchmark/tools` kiểm
  tra mọi token khai báo đúng là token mà ứng dụng benchmark chấp nhận và mọi `method/path` có trong
  `openapi.yaml`.
- `business_rules` mô tả hành vi đúng của bản sạch (ví dụ "không thể hủy đơn đã SHIPPED → 409") ở mức
  tài liệu nghiệp vụ; không nêu mã bug, biến toggle hay hành vi lỗi.

### 2. Snapshot vào `experiments.config` khi tạo thực nghiệm
`CreateExperimentAction` đọc ground truth tại thời điểm tạo và lưu `config.auth` (đủ token, để chạy lại
đúng như lúc tạo) và `config.business_rules` (mảng, có thể rỗng). Mọi bước sau chỉ đọc snapshot, không đọc
lại `ground-truth.yaml` hay business rules của project. Thực nghiệm tạo trước ADR này (không có hai khóa)
giữ hành vi cũ. Người dùng không gửi được hai khóa này qua `POST /experiments` (chỉ đọc, lấy từ ground truth).

### 3. Quy tắc dùng credential (giống nhau cho RULE, LLM, HYBRID)
Khi lượt chạy thuộc thực nghiệm có `config.auth`:

- **Biến môi trường lượt chạy**: executor nạp `auth_token_<role> = <token>` cho mọi vai trò. Biến này được
  ưu tiên hơn `variables` khai báo trong case (case không giả được token của vai trò).
- **Tiêm header mặc định**: với mỗi step gọi một operation có security trong spec (security hiệu lực, không
  chứa yêu cầu rỗng `{}`), nếu request **không** có header `Authorization` (không phân biệt hoa thường) và
  technique của case **không phải** `AUTH`, executor thêm `Authorization: Bearer {{auth_token_<role>}}`, với
  `role` là vai trò khai báo cho operation đó trong `auth.operations`, nếu không có thì `default_role`.
  Step gọi endpoint công khai không được thêm gì.
- **Case `AUTH` tự chịu trách nhiệm về credential**: executor không tiêm gì. Case muốn gửi credential đặt
  header tường minh (`Bearer {{auth_token_admin}}`), case kiểm tra thiếu/sai token thì bỏ header hoặc dùng
  token sai.
- Header tường minh trong case luôn thắng (ví dụ case SEQUENCE kiểm tra IDOR dùng `{{auth_token_customer_bob}}`).
- `request_log` vẫn che giá trị `Authorization` bằng `***` (§9); DSL đã lưu chỉ chứa placeholder.

**RULE** (đọc spec + fixture, không đọc business rules):
- Không có fixture: giữ hành vi PR #123 (endpoint có security chỉ có case thiếu credential → 401).
- Có fixture: endpoint có security được sinh đủ 6 kỹ thuật như endpoint công khai (credential do executor
  tiêm), cộng các case `AUTH`: thiếu credential → 401 và token không hợp lệ → 401 (khi spec khai báo 401);
  mỗi vai trò trong `forbidden_roles` của operation → 403 (khi spec khai báo 403).

**LLM/HYBRID** (ai-service): `POST /v1/generate` có thêm trường tùy chọn `auth` chỉ gồm `scheme`,
`default_role`, `roles[{name, description}]`, `operations` — **không có token** (`extra="forbid"` từ chối
mọi trường token). Prompt có mục "Authentication" nêu quy tắc tiêm header ở trên và các placeholder
`{{auth_token_<role>}}`. Validator Python và PHP coi các biến này là đã định nghĩa (D04 vẫn áp dụng đầy đủ:
biến khác chưa extract vẫn `DSL_INVALID`). Business rules gửi cho ai-service lấy từ snapshot (`params.business_rules`
của job, ánh xạ `method/path` sang `endpoint_id` của spec), không đọc project.

Lý do không đưa token vào prompt: token không giúp LLM sinh test tốt hơn placeholder, làm prompt và
`llm_call_logs` mang dữ liệu xác thực, và khiến RULE/LLM/HYBRID dùng hai cơ chế khác nhau.

### 4. Hợp đồng
- `ExperimentConfig` thêm `auth` (`ExperimentAuthFixture`, có token, chỉ đọc) và `business_rules`
  (`ExperimentBusinessRule[]`, chỉ đọc).
- `ExperimentRepetition` và `ExperimentExportItem.repetitions[]` thêm `generation_job_ids` (mọi job đã nạp case
  vào suite của lần lặp theo thứ tự tạo; HYBRID: job RULE seed rồi job HYBRID). `ExperimentExportItem.config`
  thêm `auth` và `business_rules`.
- `GenerationParams` thêm `auth` (không token) và `business_rules` (snapshot) do thực nghiệm đặt.
- `ai-service.openapi.yaml`: `GenerateRequest.auth` (`AuthContext`).
- `docs/contracts/db-schema.md`: mô tả `experiments.config`, `repetitions_data.generation_job_ids`, `generation_jobs.params`.
- Không đổi bảng/cột DB, không đổi Test DSL v1.0, không thêm mã lỗi.

## Hệ quả
- Tích cực: RULE, LLM, HYBRID kiểm thử được hành vi sau xác thực trên ecommerce-order và appointment-booking;
  FPR bản sạch không còn bị 401 làm sai; RULE phát hiện được bug phân quyền theo vai trò (BUG-017) bằng case
  AUTH `forbidden_roles`; thực nghiệm tái lập được (fixture và business rules nằm trong snapshot và bản xuất);
  truy vết đủ job sinh của từng lần lặp.
- Tiêu cực / rủi ro:
  - `operations[].role/forbidden_roles` và `business_rules` là tri thức do người soạn benchmark khai báo
    từ tài liệu; khai báo sai làm sai oracle. Giảm rủi ro bằng test đối chiếu với `openapi.yaml`, ứng dụng
    benchmark và kiểm tra không nhắc mã bug.
  - Bug IDOR (BUG-018, BUG-030) cần chuỗi hai người dùng; RULE không sinh chuỗi nên vẫn không phát hiện —
    đây là giới hạn của chiến lược, không phải của hạ tầng.
  - Số liệu RULE trên hai benchmark có xác thực trước và sau ADR này không so sánh trực tiếp được; báo cáo
    phải ghi rõ dùng ADR-0006.
  - **Mối đe dọa tính hợp lệ (S11-01): business rules chỉ được LLM và HYBRID đọc**, RULE không dùng. Mỗi rule
    là ngữ cảnh mà chỉ hai chiến lược này có, nên so sánh RULE với LLM/HYBRID không hoàn toàn cùng đầu vào.
    Bộ rule ban đầu lại tập trung vào operation có seeded bug và mô tả hành vi đúng ngược với bug, làm lệch
    so sánh về phía LLM/HYBRID. Giảm thiểu (S11-01, trước khi đóng băng benchmark v1.0): mỗi operation nghiệp
    vụ (trừ `/health`) có 1–2 rule, kể cả operation không có bug, nên rule phủ đều mọi operation thay vì bám
    theo bug; rule chỉ nhắc path, mã HTTP và mã lỗi có trong `openapi.yaml`/README (bỏ các mã lỗi chỉ có trong
    mã nguồn ứng dụng). `benchmark/tools/ground_truth_fixture.py` kiểm tra cả hai điều kiện. Rủi ro còn lại:
    phần lớn hành vi đã được tài liệu hóa vẫn trùng với chỗ cài bug (bug được cài bằng cách vi phạm hành vi
    đã công bố), nên chênh lệch LLM/HYBRID so với RULE vẫn có một phần đến từ business rules; báo cáo kết quả
    phải nêu điều này, và có thể đo thêm LLM/HYBRID không có business rules làm đối chứng.
  - Thực nghiệm tạo trước ADR-0006 (không có `config.business_rules`) chạy lại thì LLM/HYBRID vẫn đọc
    business rules hiện hành của project (hành vi cũ). Bản xuất ghi `config.business_rules: null` cho các
    thực nghiệm này; theo hợp đồng `ExperimentExportItem`, `null` nghĩa là "không có snapshot, đã dùng rule
    hiện hành của project lúc chạy", không phải "không có rule". Hợp đồng không thêm trường mới
    (`additionalProperties: false`).
- Việc cần làm theo (trong cùng PR): cập nhật `ground-truth.yaml` và README của ba benchmark,
  `benchmark/tools` (kiểm tra fixture), docs/DESIGN.md (D13, §5, §6), `experiment-design.md`, các hợp đồng nêu trên,
  M14 (hiển thị vai trò và business rules, chỉ đọc).

## Bổ sung 2026-10-12: cô lập từng test case trong lượt chạy thực nghiệm

- Trạng thái: Chấp nhận (CODEOWNER duyệt qua chat ngày 2026-10-12; phạm vi duyệt: tính công bằng của giao thức
  thực nghiệm)
- Task: S11-01 (trước khi đóng băng benchmark v1.0), theo rà soát PR #126

**Bối cảnh.** Đến PR #126, thực nghiệm chỉ reset sandbox một lần trước mỗi lượt chạy (bản sạch và từng biến thể
bug); các test case trong cùng lượt dùng chung trạng thái. Hệ quả đo được trên RULE:

- ecommerce-order: case checkout đầu tiên làm rỗng giỏ, case checkout sau nhận 409 (false positive trên bản sạch);
  BUG-013 được "phát hiện" chỉ vì case EQUIVALENCE checkout giỏ đã rỗng tạo ra một đơn rỗng, rồi case
  `GET /admin/orders` gặp đơn đó (rò rỉ trạng thái giữa các case, không phải nhờ case nào nhắm vào bug);
- appointment-booking: các case kỳ vọng 201 cùng đặt một bác sĩ/ngày/slot (lấy từ `examples`), chỉ case đầu
  được 201, các case sau nhận 409 `SLOT_ALREADY_BOOKED`.

Kết quả vì vậy phụ thuộc thứ tự case (id trong suite) và số case kỳ vọng thành công của mỗi chiến lược, làm lệch
FPR và BDR giữa RULE, LLM và HYBRID.

**Quyết định.**

1. Khi một TestRun chạy trong thực nghiệm (bản sạch và mọi biến thể bug, mọi chiến lược như nhau), sandbox được
   reset về dữ liệu seed **trước từng test case**: reset cấp lượt chạy (đã có) phục vụ case đầu, sau đó
   `POST <variant>/__admin/reset` trước mỗi case tiếp theo. Reset đi qua `TargetHttpClient` và allowlist (D07),
   cùng timeout `BENCHMARK_RESET_TIMEOUT_MS` như reset cấp lượt chạy. Reset lỗi thì lượt chạy FAILED (các case chưa
   chạy lưu SKIPPED) và thực nghiệm FAILED, như reset cấp lượt chạy.
2. Bật/tắt bằng `EXPERIMENT_RESET_PER_CASE` (mặc định `true`; `make experiment RESET_PER_CASE=false` để tái lập
   giao thức cũ). Giá trị được snapshot vào `experiments.config.reset_per_case` lúc tạo (chỉ đọc, client không gửi
   được) và có trong bản xuất. Thực nghiệm tạo trước bổ sung này không có khóa: coi là `false` (hành vi cũ).
3. Lượt chạy M12 thông thường (`ExecuteTestRunJob`) không đổi: không reset. (Thay bởi bổ sung 2026-10-13 bên
   dưới: lượt chạy M12 trên benchmark reset trước từng case.)
4. Một test case nhiều bước (SEQUENCE) vẫn chạy liên tục trong một trạng thái; cô lập ở mức case, không ở mức step.

**Hệ quả.**

- Tích cực: mỗi case được đánh giá trên cùng dữ liệu seed ở bản sạch và ở biến thể bug, nên D14 so sánh đúng một
  case với chính nó; kết quả không phụ thuộc thứ tự case hay số case khác trong suite; phát hiện nhờ rò rỉ trạng
  thái giữa các case (như BUG-013 ở PR #126) không còn được tính.
- Tiêu cực: thêm một request reset cho mỗi case (đo trong PR S11-01 với `make experiment STRATEGIES=RULE BENCHMARKS=all`: khoảng 6 300 lần reset
  cho 3 thực nghiệm × 3 lần lặp, thời gian mỗi thực nghiệm từ 17–18 s lên 20–21 s, tổng 52 s lên 62 s, khoảng +20 %); case
  không còn tận dụng được dữ liệu do case trước tạo ra. Chiến lược nào muốn kiểm thử chuỗi thao tác phải viết
  trong một case SEQUENCE.
- Số liệu thực nghiệm có `reset_per_case = true` không so sánh trực tiếp được với số liệu trước bổ sung này;
  báo cáo kết quả phải ghi rõ giá trị `config.reset_per_case`.
- Hợp đồng: `ExperimentConfig.reset_per_case` (chỉ đọc) và `ExperimentExportItem.config.reset_per_case` trong
  `web-api.openapi.yaml`; `experiments.config` trong `db-schema.md`; docs/DESIGN.md §7; `experiment-design.md` §4.3.1.

## Bổ sung 2026-10-13: reset benchmark trước từng test case của lượt chạy M12

- Trạng thái: Chấp nhận — duyệt bởi CODEOWNER (chat) ngày 2026-10-13 cùng PR S13-03 QA trước khi deploy. Thay mục 3 của bổ sung 2026-10-12.
- Task: S13-03, theo QA trước khi deploy công khai (ADR-0007)

**Bối cảnh.** Ba benchmark bản sạch là trạng thái dùng chung và bị thay đổi bởi chính các lượt chạy. Output RULE
là tất định và có thao tác phá hủy: cùng SKU `SKU_249`, slug `cat-202` ở mọi suite, happy path có
`DELETE /products/1`. QA đo được: chạy lại y nguyên suite simple-store (73 case) trên `http://simple-store:8000`
cho 73 PASS lần đầu, 64 PASS / 9 FAIL lần hai (409 trùng SKU, 404 sản phẩm ID 1); trên một stack khác, lần chạy
đầu tiên của một suite RULE mới đã có 5 FAIL vì người khác chạy case giống hệt trước đó. Người dùng đọc các FAIL
này như lỗi của hệ thống đích. Trên bản deploy công khai, mọi người dùng chung 3 benchmark nên verdict của một
người phụ thuộc vào việc người khác đã chạy gì.

**Quyết định.**

1. Lượt chạy M12 (`ExecuteTestRunJob`) có host của target nằm trong `RUN_RESET_BENCHMARK_HOSTS` (mặc định
   `benchmark,benchmark-order,benchmark-booking,simple-store,ecommerce-order,appointment-booking`, tức tên service và
   alias bản sạch của 3 benchmark) reset sandbox về dữ liệu seed **trước từng test case, kể cả case đầu**, cùng giao
   thức với thực nghiệm có `reset_per_case`: `POST <scheme://host:port>/__admin/reset` (bỏ đường dẫn gốc của
   target) qua `BenchmarkSandbox`, tức `TargetHttpClient` sau kiểm tra allowlist (D07), timeout
   `BENCHMARK_RESET_TIMEOUT_MS`. Sandbox không có endpoint reset (HTTP 404) được bỏ qua kèm cảnh báo trong log.
2. Target khác (hệ thống của người dùng, kể cả sandbox một bug) **không bao giờ** bị gửi request reset. Để trống
   biến thì M12 không reset (hành vi cũ).
3. Reset lỗi thì case đó được lưu `ERROR` kèm lý do và **không** được gửi (chạy trên dữ liệu bẩn sẽ ra FAIL giả);
   lượt chạy tiếp tục với case sau và kết thúc `COMPLETED`. Khác với thực nghiệm (reset lỗi làm lượt chạy FAILED)
   vì một lượt chạy M12 không phải dữ liệu đo.
4. Không đổi DSL, DB schema, API hay state machine; chỉ đổi hành vi thực thi của worker.

**Hệ quả.**

- Tích cực: chạy lại một suite hợp lệ trên benchmark sạch cho cùng verdict mỗi lần, và verdict M12 trên bản sạch
  khớp với lượt chạy bản sạch của thực nghiệm.
- Tiêu cực: thêm một request reset cho mỗi case (vài mili giây với benchmark trong cùng mạng Docker); case M12 trên
  benchmark không dùng được dữ liệu do case trước tạo, chuỗi thao tác phải viết trong một case SEQUENCE.
- Giới hạn: đảm bảo trên dựa vào việc lượt chạy M12 được xử lý **một job tại một thời điểm** (một tiến trình
  `queue:work` cho hàng đợi `default`). Từ ADR-0009, production có thêm `worker-llm` cho hàng đợi `llm` (sinh
  LLM/HYBRID, thực nghiệm); lượt chạy M12 vẫn chỉ ở `default` nên đảm bảo vẫn giữ. Thực nghiệm (hàng đợi `llm`)
  reset cùng benchmark sạch với M12, vì vậy production giữ `EXPERIMENTS_ENABLED=false`; muốn bật thực nghiệm trên
  production thì phải có sandbox riêng hoặc đưa thực nghiệm về hàng đợi `default` trước (ADR mới). Chạy nhiều worker
  cho cùng hàng đợi thì các lượt chạy trên cùng benchmark có thể xen kẽ và reset lẫn nhau (chưa làm).
- Hợp đồng/tài liệu: docs/DESIGN.md §7 (`RUN_RESET_BENCHMARK_HOSTS`, `EXPERIMENT_RESET_PER_CASE`), `web/.env.example`,
  `web/.env.production.example`, `docs/deploy.md`, `experiment-design.md` §4.3.1, màn M12.

## Bổ sung 2026-10-14: fixture xác thực cho lượt chạy M12 trên benchmark của nền tảng

- Trạng thái: Chấp nhận — quyết định được chủ dự án ủy quyền (task S14-06, QA bản deploy công khai ngày 2026-10-14).
- Task: S14-06

**Bối cảnh.** Suite "LLM/HYBRID (bản ghi thực nghiệm)" của showcase demo (S14-05) và mọi case do thực nghiệm sinh
dùng `Authorization: Bearer {{auth_token_<role>}}` hoặc dựa vào header mặc định mà executor tiêm (mục 3). Lượt chạy
ghi sẵn của demo chạy với fixture xác thực của ground truth nên cho 37 PASS / 19 FAIL / 0 ERROR; nhưng khi người xem
chạy lại cùng suite ở M12 trên `http://ecommerce-order:8000`, lượt chạy không có fixture: QA đo được 56 case cho
14 PASS / 15 FAIL / 27 ERROR (run #324). 27 ERROR là step còn placeholder `{{auth_token_customer}}` (executor từ chối
gửi), phần lớn FAIL là 401 của case dựa vào header mặc định. Thông báo lỗi khuyên chạy lại qua thực nghiệm trong khi
bản deploy có `EXPERIMENTS_ENABLED=false`, và chứa mã nội bộ (mã ADR, mã màn hình). Token của fixture là token sandbox
**công khai** của benchmark (`app/auth.py`, đã có trong `ground-truth.yaml`), không phải bí mật.

**Quyết định.**

1. Lượt chạy M12 (`ExecuteTestRunJob`) có host của target là một benchmark của nền tảng (cùng danh sách host với reset
   trước từng case: `RUN_RESET_BENCHMARK_HOSTS`) dùng fixture xác thực của benchmark đó, đọc từ
   `benchmark/<slug>/ground-truth.yaml` (`BenchmarkRunAuthResolver`). Benchmark được xác định theo host: host của
   `base_url` trong ground truth (`http://<slug>:8000`), chính slug, hoặc tên service Compose của benchmark
   (`benchmark`, `benchmark-order`, `benchmark-booking` trong `docker/docker-compose.yml`).
2. Fixture được dùng **y như lượt chạy của thực nghiệm** (mục 3): biến `auth_token_<role>` được định nghĩa, step gọi
   operation có security được thêm `Authorization: Bearer {{auth_token_<role>}}` trừ case `AUTH` hoặc case tự đặt
   header, `request_log` vẫn che giá trị `Authorization` bằng `***`.
3. Target khác (hệ thống của người dùng, sandbox một bug, host ngoài danh sách) **không bao giờ** nhận fixture.
   Benchmark không khai báo `auth` (simple-store) hoặc ground truth không đọc được thì lượt chạy không có fixture
   (hành vi cũ).
4. Step còn placeholder `{{auth_token_<role>}}` trên target không có fixture vẫn bị từ chối trước khi gửi (`ERROR`), với
   thông báo tiếng Việt không có mã ADR hay mã màn hình; chỉ gợi ý chạy trong thực nghiệm khi bản triển khai cho phép
   tạo thực nghiệm (`EXPERIMENTS_ENABLED=true`).
5. Không đổi DSL, DB schema, API hay state machine; chỉ đổi hành vi thực thi của worker.

**Hệ quả.**

- Tích cực: chạy lại suite demo hoặc suite do thực nghiệm sinh trên benchmark sạch dùng chung cho cùng verdict với
  lượt chạy đã ghi; case RULE sinh kèm fixture (mọi kỹ thuật trên endpoint có security) chạy được ở M12.
- Tiêu cực: case không phải `AUTH` cố ý gọi operation có security mà không gửi credential (để kiểm tra 401) sẽ được
  thêm header mặc định khi chạy trên benchmark của nền tảng, như trong thực nghiệm; case như vậy phải dùng technique
  `AUTH` (bộ sinh RULE đã làm vậy).
- Tài liệu: docs/DESIGN.md D13 và §10 bước 6.
