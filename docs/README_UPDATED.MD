1. Prerequisites
Confirm these are available:
php -v
composer -V
node -v
npm -v
python --version
Recommended Python:
Python 3.10.x or 3.11.x
Avoid using Python 3.14 for this service for now. DeepFace/TensorFlow dependencies are safer on 3.10 or 3.11.
2. Laravel/Laragon Setup
Open Laragon.
Make sure the project is located here:
C:\laragon\www\attendancemonitoring
In Laragon:
Start Apache/Nginx.
Start MySQL.
Make sure Laragon serves the site as:
https://attendancemonitoring.test
If needed, right-click Laragon:
Menu > www > attendancemonitoring
Then check the app in your browser:
https://attendancemonitoring.test
3. Laravel Env Setup
Open:
C:\laragon\www\attendancemonitoring\.env
Make sure these exist:
APP_URL=https://attendancemonitoring.test

FACE_SERVICE_URL=https://127.0.0.1:8001
VITE_FACE_SERVICE_URL=https://127.0.0.1:8001
Then from project root:
cd C:\laragon\www\attendancemonitoring
php artisan optimize:clear
4. Install Laravel Dependencies
From project root:
cd C:\laragon\www\attendancemonitoring
composer install
npm install
Run migrations if needed:
php artisan migrate
5. Set Up Python Face Service
Go to the face service folder:
cd C:\laragon\www\attendancemonitoring\face_service
Create or recreate the venv using Python 3.10/3.11:
python -m venv .venv
Activate it:
.venv\Scripts\activate
Confirm the venv Python version:
python --version
Install dependencies:
pip install --upgrade pip
pip install -r requirements.txt
Important: the first real recognition/enrollment request may download DeepFace model weights. That can take a while.
6. Run Python Service Tests
From:
C:\laragon\www\attendancemonitoring\face_service
With the venv activated:
pytest tests
Expected result:
passed
If you get:
ModuleNotFoundError: No module named 'app'
Make sure this file exists:
face_service\tests\conftest.py
Then rerun:
pytest tests
7. Run Laravel Focused Tests
From Laravel project root:
cd C:\laragon\www\attendancemonitoring
php artisan test tests\Feature\FaceRoutesTest.php
If Symfony complains about writing temp files to C:\Windows, run this instead:
set TMP=C:\laragon\www\attendancemonitoring\storage\framework\testing
set TEMP=C:\laragon\www\attendancemonitoring\storage\framework\testing
php artisan test tests\Feature\FaceRoutesTest.php
8. Run Frontend Build Test
From project root:
cd C:\laragon\www\attendancemonitoring
npm run build
Expected: Vite build succeeds.
If Node crashes with a crypto/CSPRNG error, try from a normal CMD outside VS Code/Codex, or reinstall/use a stable Node LTS version.
9. Run the FastAPI Face Service
From:
cd C:\laragon\www\attendancemonitoring\face_service
.venv\Scripts\activate
Run with Laragon SSL certs:
uvicorn app.main:app --host 0.0.0.0 --port 8001 --ssl-keyfile C:/laragon/etc/ssl/laragon.key --ssl-certfile C:/laragon/etc/ssl/laragon.crt
Keep this CMD window open.
Service should be available at:
https://127.0.0.1:8001
10. Test Face Service Health
Open this in your browser:
https://127.0.0.1:8001/health
Expected JSON:
{
  "ok": true,
  "database_path": "...faces.sqlite",
  "model_name": "SFace",
  "detector_backend": "yunet",
  "anti_spoofing": true
}
If browser warns about certificate trust, accept the local certificate warning for testing.
11. Run Laravel App
In Laragon, make sure Apache/Nginx and MySQL are running.
Open:
https://attendancemonitoring.test
If developing frontend assets, run Vite in another CMD:
cd C:\laragon\www\attendancemonitoring
npm run dev
12. Test Face Enrollment
In the Laravel app:
Go to Admin.
Open Employees.
Create or open an employee.
Open face registration.
Capture at least 3 images.
Use slightly different poses if possible:front
slight left
slight right

Expected behavior:
Bad lighting is rejected.
Blurry image is rejected.
Multiple faces are rejected.
Spoofed/non-live face should be rejected.
After 3 successful captures, status should show ready/enrolled.
SQLite storage is here:
C:\laragon\www\attendancemonitoring\face_service\data\faces.sqlite
13. Test Face Recognition
From the app’s attendance/timeclock face flow:
Stand in front of the camera.
Capture/recognize.
Confirm response behavior:enrolled employee should match
unknown person should be rejected
ambiguous/weak match should be rejected
spoofed face should be rejected

Expected recognition response includes:
{
  "matched": true,
  "employee_id": "...",
  "confidence": 0.0,
  "distance": 0.0,
  "margin": 0.0,
  "face_count": 1,
  "message": "Face matched.",
  "spoofing_score": 0.0,
  "spoofing_passed": true
}
14. Manual API Test With curl
Health:
curl -k https://127.0.0.1:8001/health
Enroll:
curl -k -X POST https://127.0.0.1:8001/api/employees/EMP-001/enroll ^
  -F "image=@C:\path\to\face.jpg" ^
  -F "pose_label=front"
Status:
curl -k https://127.0.0.1:8001/api/employees/EMP-001/status
Recognize:
curl -k -X POST https://127.0.0.1:8001/api/recognize ^
  -F "image=@C:\path\to\face.jpg"
Delete employee face data:
curl -k -X DELETE https://127.0.0.1:8001/api/employees/EMP-001
Checklist
Setup:

Laragon Apache/Nginx running.

Laragon MySQL running.

App opens at https://attendancemonitoring.test.

.env has FACE_SERVICE_URL=https://127.0.0.1:8001.

.env has VITE_FACE_SERVICE_URL=https://127.0.0.1:8001.

php artisan optimize:clear completed.

composer install completed.

npm install completed.

php artisan migrate completed if needed.
Python service:

Python 3.10 or 3.11 selected.

.venv created in face_service.

.venv activated.

pip install -r requirements.txt completed.

pytest tests passes.

FastAPI service runs on https://127.0.0.1:8001.

/health returns ok: true.
Verification:

php artisan test tests\Feature\FaceRoutesTest.php passes.

npm run build passes.

Face enrollment accepts 3 good captures.

Enrollment rejects no face.

Enrollment rejects multiple faces.

Enrollment rejects blurry/dark/bright captures.

Enrollment rejects spoofed face.

Recognition matches enrolled employee.

Recognition rejects unknown person.

Recognition rejects ambiguous match.

Recognition rejects spoofed face.

SQLite file exists at face_service\data\faces.sqlite.
Daily Run Order:
Start Laragon.
Start MySQL.
Open Laravel app.
Start face service: