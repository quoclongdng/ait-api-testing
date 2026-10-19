# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 20:50:11

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| ecommerce-order | HYBRID | qwen2.5-coder:7b | 0.2 | 5 | 95.1 ± 1.4 | 100.0 ± 0.0 | 18.3 ± 1.3 | 56.7 ± 9.1 | 56.7 ± 9.1 | 100.0 ± 0.0 | 86.8 ± 2.0 | 0.3 ± 0.5 | 199.9 ± 8.8 | 7.0 ± 0.3 | 87965 ± 6412 | 12165 ± 614 | 119.4 ± 3.0 | 6.8 ± 1.1 / 12 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
