import * as faceapi from 'face-api.js';

window.faceRegistration = ({ registerUrl, existingFaces = [] }) => ({
    video: null,
    overlay: null,
    captureCanvas: null,
    stream: null,
    scanInterval: null,
    statusText: 'Loading face models...',
    isCameraReady: false,
    isModelReady: false,
    isSubmitting: false,
    faceCount: 0,
    capturedPreview: '',
    message: '',
    success: false,
    existingDescriptors: [],
    duplicateThreshold: 0.52,
    modelPath: '/models/face-api',
    detectorOptions: new faceapi.TinyFaceDetectorOptions({
        inputSize: 416,
        scoreThreshold: 0.5,
    }),

    get canSave() {
        return this.faceCount === 1 && !this.isSubmitting && this.isCameraReady && this.isModelReady;
    },

    async init() {
        this.video = this.$refs.video;
        this.overlay = this.$refs.overlay;
        this.captureCanvas = this.$refs.captureCanvas;

        try {
            await this.loadModels();
            await this.loadExistingDescriptors(existingFaces);
            await this.startCamera();
            this.startScanLoop();
        } catch (error) {
            console.error(error);
            this.statusText = error instanceof Error ? error.message : 'Unable to start face registration.';
            this.message = this.statusText;
            this.success = false;
        }
    },

    destroy() {
        this.stopCamera();
        this.clearScanLoop();
    },

    async loadModels() {
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(this.modelPath),
            faceapi.nets.faceLandmark68Net.loadFromUri(this.modelPath),
            faceapi.nets.faceRecognitionNet.loadFromUri(this.modelPath),
        ]);

        this.isModelReady = true;
        this.statusText = 'Start the camera and center one face.';
    },

    async loadExistingDescriptors(faces) {
        this.existingDescriptors = [];

        for (const face of faces) {
            if (!face.profile_url) continue;

            try {
                const image = await faceapi.fetchImage(face.profile_url);
                const detection = await faceapi
                    .detectSingleFace(image, this.detectorOptions)
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                if (!detection) continue;

                this.existingDescriptors.push({
                    employeeId: face.employee_id,
                    name: face.name,
                    descriptor: detection.descriptor,
                });
            } catch (error) {
                console.error(`Unable to read registered face for ${face.employee_id}`, error);
            }
        }
    },

    async startCamera() {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error('Camera access is not available in this browser or context.');
        }

        this.stream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: {ideal: 1280},
                height: {ideal: 720},
                facingMode: 'user',
            },
            audio: false,
        });

        this.video.srcObject = this.stream;

        await new Promise((resolve) => {
            this.video.onloadedmetadata = () => {
                this.video.play();
                this.isCameraReady = true;
                resolve();
            };
        });
    },

    stopCamera() {
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
        this.isCameraReady = false;
    },

    clearScanLoop() {
        if (this.scanInterval) {
            clearInterval(this.scanInterval);
            this.scanInterval = null;
        }
    },

    startScanLoop() {
        this.clearScanLoop();

        this.scanInterval = setInterval(() => {
            this.scanFace().catch((error) => {
                console.error('Face enrollment scan failed.', error);
                this.statusText = 'Face scan failed. Check lighting and camera access.';
            });
        }, 700);
    },

    async scanFace() {
        if (!this.video || !this.overlay || !this.isCameraReady || !this.isModelReady) return;

        const displaySize = {
            width: this.video.clientWidth,
            height: this.video.clientHeight,
        };

        if (!displaySize.width || !displaySize.height) return;

        faceapi.matchDimensions(this.overlay, displaySize);

        const detections = await faceapi
            .detectAllFaces(this.video, this.detectorOptions)
            .withFaceLandmarks()
            .withFaceDescriptors();

        this.faceCount = detections.length;

        const resizedDetections = faceapi.resizeResults(detections, displaySize);
        const context = this.overlay.getContext('2d');
        context?.clearRect(0, 0, this.overlay.width, this.overlay.height);

        resizedDetections.forEach((detection, index) => {
            const drawBox = new faceapi.draw.DrawBox(detection.detection.box, {
                label: index === 0 ? 'Enrollment face' : 'Extra face',
                boxColor: detections.length === 1 ? '#f9bc60' : '#e16162',
                lineWidth: 3,
            });

            drawBox.draw(this.overlay);
        });

        if (!detections.length) {
            this.statusText = 'No face detected.';
            return;
        }

        this.statusText = detections.length === 1
            ? 'One face detected. Ready to save.'
            : 'Keep only one face in frame.';
    },

    captureBlob() {
        if (!this.video || !this.captureCanvas) return null;

        this.captureCanvas.width = this.video.videoWidth;
        this.captureCanvas.height = this.video.videoHeight;

        const context = this.captureCanvas.getContext('2d');
        if (!context) return null;

        context.drawImage(this.video, 0, 0, this.captureCanvas.width, this.captureCanvas.height);
        this.capturedPreview = this.captureCanvas.toDataURL('image/jpeg', 0.9);

        const byteString = atob(this.capturedPreview.split(',')[1]);
        const buffer = new Uint8Array(byteString.length);

        for (let i = 0; i < byteString.length; i++) {
            buffer[i] = byteString.charCodeAt(i);
        }

        return new Blob([buffer], {type: 'image/jpeg'});
    },

    async findDuplicateFace() {
        if (!this.video || !this.existingDescriptors.length) return null;

        const detection = await faceapi
            .detectSingleFace(this.video, this.detectorOptions)
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (!detection) return null;

        let bestMatch = null;

        for (const existing of this.existingDescriptors) {
            const distance = faceapi.euclideanDistance(existing.descriptor, detection.descriptor);

            if (distance > this.duplicateThreshold) continue;
            if (bestMatch && distance >= bestMatch.distance) continue;

            bestMatch = {
                ...existing,
                distance,
            };
        }

        return bestMatch;
    },

    async save() {
        if (this.faceCount !== 1) {
            this.message = 'Keep exactly one face in the camera before saving.';
            this.success = false;
            return;
        }

        const duplicate = await this.findDuplicateFace();
        if (duplicate) {
            this.message = `This face is already registered to ${duplicate.name} (${duplicate.employeeId}).`;
            this.success = false;
            this.statusText = 'Duplicate registered face detected.';
            return;
        }

        const image = this.captureBlob();
        if (!image) {
            this.message = 'Camera image is not ready.';
            this.success = false;
            return;
        }

        const formData = new FormData();
        formData.append('face-image', image, `face_registration_${Date.now()}.jpg`);

        this.isSubmitting = true;
        this.message = '';
        this.statusText = 'Saving face registration...';

        try {
            const response = await fetch(registerUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(payload.message || Object.values(payload.errors ?? {})?.[0]?.[0] || 'Unable to save face registration.');
            }

            this.success = true;
            this.message = payload.message || 'Face registered successfully.';
            this.statusText = 'Face saved.';
        } catch (error) {
            this.success = false;
            this.message = error instanceof Error ? error.message : 'Face registration failed.';
            this.statusText = 'Unable to save face registration.';
        } finally {
            this.isSubmitting = false;
        }
    },
});
