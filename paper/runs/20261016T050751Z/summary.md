# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-16 06:07:17

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| simple-store | RULE | qwen3-coder:30b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 0.0 ± 0.0 | 80.0 ± 0.0 | 80.0 ± 0.0 | 100.0 ± 0.0 | 87.5 ± 0.0 | 0.0 ± 0.0 | 0.9 ± 0.1 | 2.4 ± 0.1 | 0 ± 0 | 0 ± 0 | 59.0 ± 0.0 | 8.0 ± 0.0 / 10 |
| simple-store | LLM | qwen3-coder:30b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 23.6 ± 0.5 | 40.0 ± 0.0 | 40.0 ± 0.0 | 100.0 ± 0.0 | 83.3 ± 0.0 | 0.0 ± 0.0 | 212.6 ± 4.0 | 1.0 ± 0.0 | 17858 ± 679 | 3329 ± 67 | 25.4 ± 0.5 | 4.0 ± 0.0 / 10 |
| simple-store | HYBRID | qwen3-coder:30b | 0.2 | 5 | 99.5 ± 0.6 | 100.0 ± 0.0 | 10.1 ± 1.3 | 90.0 ± 0.0 | 90.0 ± 0.0 | 100.0 ± 0.0 | 100.0 ± 0.0 | 0.0 ± 0.0 | 452.1 ± 42.1 | 4.8 ± 0.3 | 33349 ± 1836 | 6757 ± 635 | 85.8 ± 0.4 | 9.0 ± 0.0 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
