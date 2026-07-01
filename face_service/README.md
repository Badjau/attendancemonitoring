# Face Recognition Service

Local FastAPI service for attendance face enrollment and recognition.

## Setup

```bat
cd C:\laragon\www\attendancemonitoring\face_service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

If `face_recognition` fails to install on Windows, install `dlib` from a compatible prebuilt wheel or use Conda, then rerun `pip install -r requirements.txt`.

## Run

```bat
uvicorn app.main:app --host 0.0.0.0 --port 8001 --ssl-keyfile C:/laragon/etc/ssl/laragon.key --ssl-certfile C:/laragon/etc/ssl/laragon.crt
```

Laravel/Vite should use:

```env
FACE_SERVICE_URL=https://127.0.0.1:8001
VITE_FACE_SERVICE_URL=https://127.0.0.1:8001
```

Embeddings are stored in `data/faces.sqlite`.
