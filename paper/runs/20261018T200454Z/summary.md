# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 20:07:23

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| simple-store | RULE | qwen2.5-coder:7b | 0.2 | 5 | 100.0 ± 0.0 | 100.0 ± 0.0 | 0.0 ± 0.0 | 90.0 ± 0.0 | 90.0 ± 0.0 | 100.0 ± 0.0 | 87.5 ± 0.0 | 0.0 ± 0.0 | 1.1 ± 0.7 | 3.4 ± 0.1 | 0 ± 0 | 0 ± 0 | 86.0 ± 0.0 | 9.0 ± 0.0 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
