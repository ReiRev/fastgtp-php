# A fastgtp server for the integration tests, with GNU Go and KataGo's CPU
# build, so the tests run the same way on any machine and in CI.
FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnugo unzip \
    && rm -rf /var/lib/apt/lists/*

# The Eigen (CPU) build, so no GPU is needed, and a small net from KataGo's
# own tests, which is fast enough on a CPU.
ARG KATAGO_URL=https://github.com/lightvector/KataGo/releases/download/v1.18.0/katago-v1.18.0-eigen-linux-x64.zip
ARG KATAGO_SHA256=3e062b4ba90d5a2d986bc0312a61e6b7abdf92235e59db13f1febb30f643297d
ARG MODEL_URL=https://github.com/lightvector/KataGo/raw/v1.18.0/cpp/tests/models/g170-b6c96-s175395328-d26788732.bin.gz
ARG MODEL_SHA256=f5d32604e3675c480c7c8f6aa579a1ea857135628a0afccc8fa56330fbacd38d
RUN curl -sfL -o /tmp/katago.zip "$KATAGO_URL" \
    && curl -sfL -o /tmp/model.bin.gz "$MODEL_URL" \
    && echo "$KATAGO_SHA256  /tmp/katago.zip" | sha256sum -c \
    && echo "$MODEL_SHA256  /tmp/model.bin.gz" | sha256sum -c \
    && unzip -q /tmp/katago.zip -d /opt/katago \
    && mv /tmp/model.bin.gz /opt/katago/model.bin.gz \
    && rm /tmp/katago.zip \
    # The binary is an AppImage, which needs FUSE to mount itself. A
    # container has no FUSE, so unpack it once here instead.
    && cd /opt/katago && ./katago --appimage-extract > /dev/null && rm katago \
    && /opt/katago/squashfs-root/AppRun version

# A fixed fastgtp release from PyPI, the package users install, so a new
# release cannot change the results here. Raise it in a pull request.
ARG FASTGTP_VERSION=0.1.0
RUN pip install --no-cache-dir "fastgtp==${FASTGTP_VERSION}"

COPY katago.toml /etc/fastgtp/katago.toml
ENV FASTGTP_ENGINE="gnugo --mode gtp" \
    FASTGTP_KATAGO_CONFIG=/etc/fastgtp/katago.toml

EXPOSE 8000
HEALTHCHECK --interval=2s --timeout=5s --retries=60 \
    CMD curl -sf http://127.0.0.1:8000/katago || exit 1
CMD ["uvicorn", "fastgtp.server.main:app", "--host", "0.0.0.0", "--port", "8000"]
