# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 18:21:33

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| appointment-booking | HYBRID | qwen3-coder:30b | 0.2 | 5 | 60.0 ± 54.8 | 60.0 ± 54.8 | 0.0 ± 0.0 | 12.0 ± 11.0 | 12.0 ± 11.0 | 60.0 ± 54.8 | 45.0 ± 41.1 | 0.0 ± 0.0 | 1320.6 ± 0.3 | 3.1 ± 0.2 | 1976 ± 1804 | 254 ± 232 | 27.0 ± 24.6 | 1.2 ± 1.1 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
