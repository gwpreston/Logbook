# PaddleOCR for Logbook's optional `ocr` compose profile (Phase 53.1, spec
# §7.27, #495): PaddleX basic serving of the general OCR pipeline on port
# 8080 (POST /ocr). Built locally because there is no official arm64
# serving image; PyPI has CPU wheels of PaddlePaddle for amd64 and arm64.
# PaddlePaddle 3.2.2, not 3.3: 3.3's oneDNN/PIR CPU path fails every request
# on x86 ("ConvertPirAttribute2RuntimeAttribute not support", Paddle #77340).
#
# Every Python package comes from PyPI at a pinned version with hashes
# (Phase 53.15, K79): requirements.txt is requirements.in resolved by
# pip-compile, binary wheels only, with the `ocr` and `serving` extras that
# `paddlex --install serving` used to fetch unpinned. To bump a version, edit
# requirements.in and run, from this folder:
#   docker run --rm -v "$PWD":/w -w /w python:3.12-slim sh -c 'pip install -q pip-tools && \
#     pip-compile --generate-hashes --allow-unsafe --strip-extras --no-header \
#     --output-file=requirements.txt --pip-args=--only-binary=:all: requirements.in'
#
# It runs as the unprivileged user `paddle`; models download on first start
# into /home/paddle/.paddlex (a volume).
FROM python:3.12-slim

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libgl1 libglib2.0-0 libgomp1; \
    rm -rf /var/lib/apt/lists/*; \
    useradd --create-home --uid 10001 --shell /usr/sbin/nologin paddle; \
    install -d -o paddle -g paddle /home/paddle/.paddlex

COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir --require-hashes -r /tmp/requirements.txt && rm /tmp/requirements.txt

USER paddle
WORKDIR /home/paddle
EXPOSE 8080
CMD ["paddlex", "--serve", "--pipeline", "OCR", "--device", "cpu", "--host", "0.0.0.0", "--port", "8080"]
