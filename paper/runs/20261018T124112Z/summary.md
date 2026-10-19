# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 14:35:05

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| simple-store | HYBRID | qwen3-coder:30b | 0.2 | 5 | 39.1 ± 53.6 | 40.0 ± 54.8 | 4.6 ± 0.0 | 34.0 ± 46.7 | 34.0 ± 46.7 | 40.0 ± 54.8 | 36.7 ± 50.2 | 0.0 ± 0.0 | 1320.8 ± 0.5 | 4.4 ± 0.7 | 3160 ± 4515 | 593 ± 837 | 26.6 ± 36.4 | 3.4 ± 4.7 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
