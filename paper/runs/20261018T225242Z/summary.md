# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-19 00:30:49

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| appointment-booking | HYBRID | qwen3-coder:30b | 0.2 | 5 | 92.5 ± 1.7 | 100.0 ± 0.0 | 11.9 ± 4.5 | 44.0 ± 5.5 | 44.0 ± 5.5 | 100.0 ± 0.0 | 86.3 ± 3.6 | 0.0 ± 0.0 | 1146.9 ± 252.8 | 5.3 ± 0.6 | 61180 ± 8671 | 9058 ± 1292 | 72.0 ± 0.7 | 4.4 ± 0.5 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
