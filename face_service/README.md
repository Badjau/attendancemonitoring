# Face Recognition Service

Local FastAPI service for attendance face enrollment and recognition.

The service stores numeric DeepFace SFace embeddings in SQLite and compares
those vectors with cosine distance. It does not compare raw images.

## Setup

```bat
cd C:\laragon\www\attendancemonitoring\face_service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

The first request may take longer while DeepFace downloads the SFace, YuNet,
and anti-spoofing model weights.

## Run

```bat
python run.py
```

`run.py` selects the Windows selector event loop before Uvicorn starts. This
avoids noisy `WinError 10054` connection teardown tracebacks after requests
complete.

Laravel/Vite should use:

```env
FACE_SERVICE_URL=https://127.0.0.1:8001
VITE_FACE_SERVICE_URL=https://127.0.0.1:8001
```

Embeddings are stored in `data/faces.sqlite` with model, detector, pose, quality,
and timestamp metadata. 
