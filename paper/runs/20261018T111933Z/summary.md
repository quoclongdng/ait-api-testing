# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 12:41:08

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| appointment-booking | HYBRID | qwen2.5-coder:7b | 0.2 | 5 | 84.8 ± 4.1 | 100.0 ± 0.0 | 16.3 ± 4.7 | 28.0 ± 8.4 | 28.0 ± 8.4 | 100.0 ± 0.0 | 80.6 ± 2.6 | 0.0 ± 0.0 | 948.6 ± 148.1 | 5.0 ± 1.0 | 101656 ± 17096 | 15847 ± 2529 | 72.6 ± 3.1 | 2.8 ± 0.8 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
