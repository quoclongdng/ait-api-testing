# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 16:28:46

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ecommerce-order | HYBRID | qwen3-coder:30b | 0.2 | 5 | 77.7 ± 43.4 | 80.0 ± 44.7 | 9.1 ± 0.7 | 35.0 ± 19.9 | 35.0 ± 19.9 | 80.0 ± 44.7 | 62.9 ± 35.1 | 0.0 ± 0.0 | 1320.9 ± 0.2 | 8.3 ± 1.5 | 8293 ± 4636 | 1083 ± 616 | 68.0 ± 38.0 | 4.2 ± 2.4 / 12 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
