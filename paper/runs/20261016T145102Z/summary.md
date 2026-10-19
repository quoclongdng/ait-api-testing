# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-16 16:06:32

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| appointment-booking | RULE | qwen3-coder:30b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 0.0 ± 0.0 | 20.0 ± 0.0 | 20.0 ± 0.0 | 100.0 ± 0.0 | 75.0 ± 0.0 | 0.0 ± 0.0 | 0.6 ± 0.6 | 1.8 ± 0.2 | 0 ± 0 | 0 ± 0 | 42.0 ± 0.0 | 2.0 ± 0.0 / 10 |
| appointment-booking | LLM | qwen3-coder:30b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 35.1 ± 3.7 | 0.0 ± 0.0 | 0.0 ± 0.0 | 100.0 ± 0.0 | 64.4 ± 2.8 | 0.0 ± 0.0 | 326.0 ± 26.9 | 1.2 ± 0.0 | 29932 ± 1152 | 5068 ± 367 | 29.6 ± 0.5 | 0.0 ± 0.0 / 10 |
| appointment-booking | HYBRID | qwen3-coder:30b | 0.2 | 5 | 97.1 ± 1.0 | 100.0 ± 0.0 | 16.0 ± 3.7 | 28.0 ± 4.5 | 28.0 ± 4.5 | 100.0 ± 0.0 | 85.6 ± 2.8 | 0.0 ± 0.0 | 535.0 ± 69.2 | 3.3 ± 0.2 | 57948 ± 3906 | 7914 ± 928 | 69.4 ± 1.7 | 2.8 ± 0.4 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
