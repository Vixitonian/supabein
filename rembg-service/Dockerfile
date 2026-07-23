FROM python:3.11-slim

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends \
    libgl1 libglib2.0-0 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Pre-download the model FILE at build time (bakes it into the image, no
# runtime network needed) -- u2netp, rembg's small "portable" model, fits a
# free-tier 512MB instance; the app itself still constructs the actual
# inference session lazily on first request rather than at boot, so gunicorn
# can bind and pass health checks immediately.
RUN python -c "from rembg import new_session; new_session('u2netp')"

COPY app.py .

ENV PORT=10000
ENV OMP_NUM_THREADS=1
ENV ORT_NUM_THREADS=1
EXPOSE 10000

CMD ["gunicorn", "--bind", "0.0.0.0:10000", "--workers", "1", "--timeout", "120", "app:app"]
