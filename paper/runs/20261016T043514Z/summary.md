# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-16 05:07:48

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| appointment-booking | RULE | qwen2.5-coder:7b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 0.0 ± 0.0 | 20.0 ± 0.0 | 20.0 ± 0.0 | 100.0 ± 0.0 | 75.0 ± 0.0 | 0.0 ± 0.0 | 0.4 ± 0.1 | 2.0 ± 0.3 | 0 ± 0 | 0 ± 0 | 42.0 ± 0.0 | 2.0 ± 0.0 / 10 |
| appointment-booking | LLM | qwen2.5-coder:7b | 0.2 | 5 | 88.3 ± 4.4 | 100.0 ± 0.0 | 42.9 ± 8.4 | 4.0 ± 5.5 | 4.0 ± 5.5 | 94.0 ± 5.5 | 56.3 ± 6.3 | 2.2 ± 5.0 | 92.4 ± 8.1 | 1.0 ± 0.1 | 38979 ± 8835 | 5466 ± 378 | 22.0 ± 3.1 | 0.4 ± 0.5 / 10 |
| appointment-booking | HYBRID | qwen2.5-coder:7b | 0.2 | 5 | 83.6 ± 2.2 | 100.0 ± 0.0 | 13.9 ± 2.1 | 36.0 ± 11.4 | 36.0 ± 11.4 | 100.0 ± 0.0 | 83.1 ± 1.7 | 0.8 ± 1.9 | 266.6 ± 21.9 | 3.0 ± 0.2 | 115905 ± 3473 | 16782 ± 1705 | 70.6 ± 1.7 | 3.6 ± 1.1 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
