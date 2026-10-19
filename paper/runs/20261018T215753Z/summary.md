# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 22:52:39

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ecommerce-order | HYBRID | qwen3-coder:30b | 0.2 | 5 | 97.9 ± 0.8 | 100.0 ± 0.0 | 13.9 ± 0.5 | 68.3 ± 3.7 | 68.3 ± 3.7 | 100.0 ± 0.0 | 87.1 ± 0.8 | 0.0 ± 0.0 | 620.4 ± 53.4 | 7.4 ± 0.3 | 68525 ± 3821 | 9487 ± 714 | 114.6 ± 0.9 | 8.2 ± 0.4 / 12 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
