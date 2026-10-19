# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 21:57:50

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| simple-store | HYBRID | qwen3-coder:30b | 0.2 | 5 | 99.3 ± 0.6 | 100.0 ± 0.0 | 9.6 ± 1.0 | 90.0 ± 0.0 | 90.0 ± 0.0 | 100.0 ± 0.0 | 100.0 ± 0.0 | 0.0 ± 0.0 | 494.4 ± 38.7 | 4.8 ± 0.8 | 39760 ± 3298 | 7657 ± 515 | 86.0 ± 0.0 | 9.0 ± 0.0 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
