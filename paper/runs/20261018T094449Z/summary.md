# Tóm tắt thực nghiệm (make experiment)

Xuất lúc (UTC): 2026-10-18 10:21:44

Giá trị = trung bình ± độ lệch chuẩn mẫu qua các lần lặp của thực nghiệm COMPLETED (docs/DESIGN.md §11).
Tỷ lệ tính bằng %, thời gian bằng giây; "—" = không áp dụng (mẫu số bằng 0 ở mọi lần lặp hoặc chưa có lần lặp hoàn tất).

| Benchmark | Chiến lược | Model | T | Lần lặp | VR | ER | FPR | BDR | MS | EC | SCC | Redundancy | Sinh (s) | Chạy (s) | Prompt tokens | Completion tokens | Case sinh ra | Bug phát hiện |
|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| simple-store | HYBRID | qwen2.5-coder:7b | 0.2 | 5 | 91.7 ± 3.1 | 100.0 ± 0.0 | 7.2 ± 2.3 | 88.0 ± 4.5 | 88.0 ± 4.5 | 100.0 ± 0.0 | 94.2 ± 3.7 | 0.0 ± 0.0 | 408.1 ± 180.7 | 6.3 ± 1.0 | 64597 ± 8092 | 11872 ± 923 | 84.4 ± 1.5 | 8.8 ± 0.4 / 10 |

Chú thích: VR Validity Rate, ER Executable Rate, FPR False Positive Rate, BDR Bug Detection Rate (D14),
MS Mutation Score, EC Endpoint Coverage, SCC Status Code Coverage, T temperature.
