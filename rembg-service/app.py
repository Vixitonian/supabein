import io
import os
from flask import Flask, request, Response, jsonify
from rembg import remove, new_session

app = Flask(__name__)

# General-purpose object segmentation (isnet-general-use) -- FlyGen only ever
# sends icon/object-style images here, never people, so the human-specific
# u2net_human_seg model would be the wrong tool for this job.
SESSION = new_session("isnet-general-use")

SHARED_SECRET = os.environ.get("SHARED_SECRET", "")

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
        output_bytes = remove(input_bytes, session=SESSION)
    except Exception as e:
        return jsonify({"error": str(e)}), 500
    return Response(output_bytes, mimetype="image/png")

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 10000))
    app.run(host="0.0.0.0", port=port)
