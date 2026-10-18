# PaddleOCR for Logbook's optional `ocr` compose profile (Phase 53.1, spec
# §7.27, #495): PaddleX basic serving of the general OCR pipeline on port
# 8080 (POST /ocr). Built locally because there is no official arm64
# serving image; PaddlePaddle's own index has CPU wheels for amd64 and arm64.
# PaddlePaddle 3.2.2, not 3.3: 3.3's oneDNN/PIR CPU path fails every request
# on x86 ("ConvertPirAttribute2RuntimeAttribute not support", Paddle #77340).
# Models download on first start into /root/.paddlex (a volume).
FROM python:3.12-slim

ARG PADDLEPADDLE_VERSION=3.2.2
ARG PADDLEX_VERSION=3.7.2

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libgl1 libglib2.0-0 libgomp1; \
    rm -rf /var/lib/apt/lists/*; \
    pip install --no-cache-dir "paddlepaddle==${PADDLEPADDLE_VERSION}" \
        -i https://www.paddlepaddle.org.cn/packages/stable/cpu/; \
    pip install --no-cache-dir "paddlex[ocr]==${PADDLEX_VERSION}"; \
    paddlex --install serving

EXPOSE 8080
CMD ["paddlex", "--serve", "--pipeline", "OCR", "--device", "cpu", "--host", "0.0.0.0", "--port", "8080"]
