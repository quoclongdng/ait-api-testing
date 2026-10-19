# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 20:10:12

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ecommerce-order | RULE | qwen2.5-coder:7b | 0.2 | 5 | 99.0 ± 0.0 | 100.0 ± 0.0 | 11.7 ± 0.0 | 41.7 ± 0.0 | 41.7 ± 0.0 | 100.0 ± 0.0 | 82.1 ± 0.0 | 1.1 ± 0.0 | 0.6 ± 0.1 | 4.8 ± 0.2 | 0 ± 0 | 0 ± 0 | 95.0 ± 0.0 | 5.0 ± 0.0 / 12 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
