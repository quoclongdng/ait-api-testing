# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-16 07:47:29

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ecommerce-order | RULE | qwen3-coder:30b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 9.2 ± 0.0 | 41.7 ± 0.0 | 41.7 ± 0.0 | 100.0 ± 0.0 | 78.6 ± 0.0 | 0.0 ± 0.0 | 0.8 ± 0.1 | 5.3 ± 0.8 | 0 ± 0 | 0 ± 0 | 76.0 ± 0.0 | 5.0 ± 0.0 / 12 |
| ecommerce-order | LLM | qwen3-coder:30b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 30.7 ± 3.5 | 23.3 ± 3.7 | 23.3 ± 3.7 | 100.0 ± 0.0 | 56.1 ± 1.0 | 0.0 ± 0.0 | 393.0 ± 31.0 | 2.3 ± 0.2 | 32530 ± 1891 | 5003 ± 196 | 38.4 ± 0.5 | 2.8 ± 0.4 / 12 |
| ecommerce-order | HYBRID | qwen3-coder:30b | 0.2 | 5 | 99.0 ± 0.4 | 100.0 ± 0.0 | 14.4 ± 0.7 | 56.7 ± 7.0 | 56.7 ± 7.0 | 100.0 ± 0.0 | 88.2 ± 1.0 | 0.0 ± 0.0 | 735.4 ± 34.0 | 9.4 ± 1.0 | 68216 ± 3051 | 8743 ± 424 | 115.0 ± 0.0 | 6.8 ± 0.8 / 12 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
