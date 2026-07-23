import os

# Bound onnxruntime's thread pools before it's imported (via rembg) -- the
# default multi-threaded execution allocates noticeably more memory, which
# matters a lot on a 512MB free-tier instance.
os.environ.setdefault("OMP_NUM_THREADS", "1")
os.environ.setdefault("ORT_NUM_THREADS", "1")

from flask import Flask, request, Response, jsonify
from rembg import remove, new_session

app = Flask(__name__)

SHARED_SECRET = os.environ.get("SHARED_SECRET", "")

# u2netp -- rembg's small "portable" model (~4.5MB vs. isnet-general-use's
# ~176MB), specifically built for memory-constrained environments like a
# free-tier instance. Lower cutout quality than isnet-general-use, but that
# tradeoff is what makes this fit in 512MB at all. Loaded lazily on first
# request rather than at import time, so the worker process can boot and
# start passing health checks immediately instead of holding up startup
# (and risking an OOM before ever accepting a connection).
_session = None
def get_session():
    global _session
    if _session is None:
        _session = new_session("u2netp")
    return _session

@app.get("/health")
def health():
    return jsonify({"status": "ok"})

@app.post("/remove-background")
def remove_background():
    if SHARED_SECRET and request.headers.get("X-Shared-Secret", "") != SHARED_SECRET:
        return jsonify({"error": "unauthorized"}), 401
    if "image" not in request.files:
        return jsonify({"error": "multipart field 'image' is required"}), 422
    file = request.files["image"]
    input_bytes = file.read()
    if not input_bytes:
        return jsonify({"error": "empty image"}), 422
    try:
        output_bytes = remove(input_bytes, session=get_session())
    except Exception as e:
        return jsonify({"error": str(e)}), 500
    return Response(output_bytes, mimetype="image/png")

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 10000))
    app.run(host="0.0.0.0", port=port)
