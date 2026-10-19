# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-16 04:35:11

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ecommerce-order | RULE | qwen2.5-coder:7b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 9.2 ± 0.0 | 41.7 ± 0.0 | 41.7 ± 0.0 | 100.0 ± 0.0 | 78.6 ± 0.0 | 0.0 ± 0.0 | 0.7 ± 0.1 | 4.2 ± 0.2 | 0 ± 0 | 0 ± 0 | 76.0 ± 0.0 | 5.0 ± 0.0 / 12 |
| ecommerce-order | LLM | qwen2.5-coder:7b | 0.2 | 5 | 90.1 ± 4.8 | 100.0 ± 0.0 | 39.1 ± 3.7 | 13.3 ± 4.6 | 13.3 ± 4.6 | 95.4 ± 4.2 | 43.2 ± 5.4 | 0.6 ± 1.4 | 108.6 ± 12.3 | 1.5 ± 0.3 | 49688 ± 7878 | 6738 ± 912 | 30.6 ± 1.5 | 1.6 ± 0.5 / 12 |
| ecommerce-order | HYBRID | qwen2.5-coder:7b | 0.2 | 5 | 93.3 ± 2.0 | 100.0 ± 0.0 | 16.5 ± 3.0 | 51.7 ± 7.0 | 51.7 ± 7.0 | 100.0 ± 0.0 | 82.9 ± 1.0 | 0.7 ± 1.5 | 221.6 ± 24.5 | 7.0 ± 0.5 | 100862 ± 10854 | 14016 ± 1725 | 116.4 ± 3.4 | 6.2 ± 0.8 / 12 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
