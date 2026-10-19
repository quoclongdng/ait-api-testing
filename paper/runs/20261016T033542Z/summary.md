# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-16 04:02:49

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| simple-store | RULE | qwen2.5-coder:7b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 0.0 ± 0.0 | 80.0 ± 0.0 | 80.0 ± 0.0 | 100.0 ± 0.0 | 87.5 ± 0.0 | 0.0 ± 0.0 | 1.1 ± 0.6 | 2.5 ± 0.1 | 0 ± 0 | 0 ± 0 | 59.0 ± 0.0 | 8.0 ± 0.0 / 10 |
| simple-store | LLM | qwen2.5-coder:7b | 0.2 | 5 | 88.3 ± 11.5 | 100.0 ± 0.0 | 29.0 ± 8.6 | 26.0 ± 13.4 | 26.0 ± 13.4 | 86.7 ± 12.2 | 63.3 ± 11.2 | 2.0 ± 4.5 | 74.0 ± 24.1 | 0.9 ± 0.2 | 30603 ± 5187 | 4663 ± 1623 | 21.2 ± 2.4 | 2.6 ± 1.3 / 10 |
| simple-store | HYBRID | qwen2.5-coder:7b | 0.2 | 5 | 90.5 ± 3.6 | 100.0 ± 0.0 | 8.1 ± 3.1 | 90.0 ± 0.0 | 90.0 ± 0.0 | 100.0 ± 0.0 | 96.7 ± 1.9 | 0.5 ± 0.7 | 202.5 ± 24.1 | 3.9 ± 0.3 | 67431 ± 7497 | 13221 ± 1443 | 84.2 ± 0.8 | 9.0 ± 0.0 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
