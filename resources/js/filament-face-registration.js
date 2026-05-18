import * as faceapi from 'face-api.js'

window.faceRegistration = ({ registerUrl, existingFaces = [] }) => ({
    video: null,
    overlay: null,
    captureCanvas: null,
    analysisCanvas: null,
    stream: null,
    scanInterval: null,
    statusText: 'Loading face models...',
    isCameraReady: false,
    isModelReady: false,
    isSubmitting: false,
    faceCount: 0,
    faceInOval: false,
    faceClear: false,
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
        return (
            this.faceCount === 1 &&
            this.faceInOval &&
            this.faceClear &&
            !this.isSubmitting &&
            this.isCameraReady &&
            this.isModelReady
        )
    },

    get ovalStatusClass() {
        if (!this.isCameraReady || !this.isModelReady) {
            return 'border-white/70'
        }

        return this.faceCount === 1 && this.faceInOval && this.faceClear
            ? 'border-success-300 shadow-[0_0_18px_rgba(34,197,94,0.5)]'
            : 'border-warning-300 shadow-[0_0_14px_rgba(251,191,36,0.35)]'
    },

    ovalBounds(width, height) {
        const ovalWidth = width * 0.3
        const ovalHeight = height * 0.76

        return {
            x: (width - ovalWidth) / 2,
            y: (height - ovalHeight) / 2,
            width: ovalWidth,
            height: ovalHeight,
            centerX: width / 2,
            centerY: height / 2,
            radiusX: ovalWidth / 2,
            radiusY: ovalHeight / 2,
        }
    },

    isBoxInsideOval(box, oval) {
        const centerX = box.x + box.width / 2
        const centerY = box.y + box.height / 2
        const normalizedCenter =
            (centerX - oval.centerX) ** 2 / oval.radiusX ** 2 +
            (centerY - oval.centerY) ** 2 / oval.radiusY ** 2

        return (
            normalizedCenter <= 0.82 &&
            box.width <= oval.width * 1.06 &&
            box.height <= oval.height * 1.05 &&
            box.width >= oval.width * 0.28 &&
            box.height >= oval.height * 0.34
        )
    },

    isPointInsideOval(point, oval, tolerance = 1) {
        return (
            (point.x - oval.centerX) ** 2 / (oval.radiusX * tolerance) ** 2 +
                (point.y - oval.centerY) ** 2 /
                    (oval.radiusY * tolerance) ** 2 <=
            1
        )
    },

    areLandmarksInsideOval(landmarks, oval) {
        const points = [
            ...landmarks.getJawOutline().slice(1, 16),
            ...landmarks.getLeftEye(),
            ...landmarks.getRightEye(),
            ...landmarks.getNose(),
            ...landmarks.getMouth(),
        ]

        const insideCount = points.filter((point) =>
            this.isPointInsideOval(point, oval, 1.08),
        ).length

        return insideCount / points.length >= 0.9
    },

    pointBounds(points, paddingX = 0, paddingY = 0) {
        const xs = points.map((point) => point.x)
        const ys = points.map((point) => point.y)

        return {
            x: Math.min(...xs) - paddingX,
            y: Math.min(...ys) - paddingY,
            width: Math.max(...xs) - Math.min(...xs) + paddingX * 2,
            height: Math.max(...ys) - Math.min(...ys) + paddingY * 2,
        }
    },

    sampleRegion(context, bounds, width, height) {
        const xStart = Math.max(0, Math.floor(bounds.x))
        const yStart = Math.max(0, Math.floor(bounds.y))
        const xEnd = Math.min(width, Math.ceil(bounds.x + bounds.width))
        const yEnd = Math.min(height, Math.ceil(bounds.y + bounds.height))

        if (xEnd <= xStart || yEnd <= yStart) {
            return null
        }

        const image = context.getImageData(
            xStart,
            yStart,
            xEnd - xStart,
            yEnd - yStart,
        )
        const luminanceValues = []
        let darkPixels = 0
        let brightPixels = 0

        for (let index = 0; index < image.data.length; index += 16) {
            const red = image.data[index]
            const green = image.data[index + 1]
            const blue = image.data[index + 2]
            const luminance = 0.2126 * red + 0.7152 * green + 0.0722 * blue

            luminanceValues.push(luminance)

            if (luminance < 55) {
                darkPixels++
            }

            if (luminance > 220) {
                brightPixels++
            }
        }

        const mean =
            luminanceValues.reduce((sum, value) => sum + value, 0) /
            luminanceValues.length
        const variance =
            luminanceValues.reduce(
                (sum, value) => sum + (value - mean) ** 2,
                0,
            ) / luminanceValues.length

        return {
            mean,
            stdDev: Math.sqrt(variance),
            darkRatio: darkPixels / luminanceValues.length,
            brightRatio: brightPixels / luminanceValues.length,
        }
    },

    analyzeFaceObstruction(detection, displaySize) {
        this.analysisCanvas ??= document.createElement('canvas')
        this.analysisCanvas.width = displaySize.width
        this.analysisCanvas.height = displaySize.height

        const context = this.analysisCanvas.getContext('2d', {
            willReadFrequently: true,
        })

        if (!context) {
            return { blocked: true, message: 'Unable to inspect face clearly.' }
        }

        context.drawImage(
            this.video,
            0,
            0,
            displaySize.width,
            displaySize.height,
        )

        const landmarks = detection.landmarks
        const box = detection.detection.box
        const faceStats = this.sampleRegion(
            context,
            box,
            displaySize.width,
            displaySize.height,
        )
        const leftEyeStats = this.sampleRegion(
            context,
            this.pointBounds(
                landmarks.getLeftEye(),
                box.width * 0.08,
                box.height * 0.05,
            ),
            displaySize.width,
            displaySize.height,
        )
        const rightEyeStats = this.sampleRegion(
            context,
            this.pointBounds(
                landmarks.getRightEye(),
                box.width * 0.08,
                box.height * 0.05,
            ),
            displaySize.width,
            displaySize.height,
        )
        const noseStats = this.sampleRegion(
            context,
            this.pointBounds(
                landmarks.getNose(),
                box.width * 0.08,
                box.height * 0.04,
            ),
            displaySize.width,
            displaySize.height,
        )
        const mouthStats = this.sampleRegion(
            context,
            this.pointBounds(
                landmarks.getMouth(),
                box.width * 0.08,
                box.height * 0.07,
            ),
            displaySize.width,
            displaySize.height,
        )
        const eyeBandStats = this.sampleRegion(
            context,
            this.pointBounds(
                [...landmarks.getLeftEye(), ...landmarks.getRightEye()],
                box.width * 0.12,
                box.height * 0.08,
            ),
            displaySize.width,
            displaySize.height,
        )
        const leftEye = landmarks.getLeftEye()
        const rightEye = landmarks.getRightEye()
        const bridgeStats = this.sampleRegion(
            context,
            this.pointBounds(
                [leftEye[0], leftEye[3], rightEye[0], rightEye[3]],
                box.width * 0.03,
                box.height * 0.06,
            ),
            displaySize.width,
            displaySize.height,
        )

        if (
            !faceStats ||
            !leftEyeStats ||
            !rightEyeStats ||
            !noseStats ||
            !mouthStats ||
            !eyeBandStats ||
            !bridgeStats
        ) {
            return { blocked: true, message: 'Keep your full face visible.' }
        }

        const eyeMean = (leftEyeStats.mean + rightEyeStats.mean) / 2
        const eyeDarkRatio =
            (leftEyeStats.darkRatio + rightEyeStats.darkRatio) / 2
        const eyeLowDetail =
            leftEyeStats.stdDev < 13 && rightEyeStats.stdDev < 13
        const eyesCovered =
            (eyeMean < faceStats.mean * 0.62 && eyeDarkRatio > 0.32) ||
            eyeDarkRatio > 0.52 ||
            (eyeLowDetail && eyeDarkRatio > 0.28)

        if (eyesCovered) {
            return {
                blocked: true,
                message: 'Remove shades or anything covering the eyes.',
            }
        }

        const eyewearDetected =
            eyeBandStats.brightRatio > 0.08 ||
            (eyeBandStats.darkRatio > 0.19 && eyeBandStats.stdDev > 42) ||
            (bridgeStats.darkRatio > 0.2 && bridgeStats.stdDev > 28) ||
            (leftEyeStats.brightRatio > 0.1 && rightEyeStats.brightRatio > 0.1)

        if (eyewearDetected) {
            return {
                blocked: true,
                message:
                    'Remove eyeglasses, shades, or anything covering the eyes.',
            }
        }

        const lowerFaceCovered =
            mouthStats.darkRatio > 0.46 ||
            mouthStats.brightRatio > 0.72 ||
            noseStats.darkRatio > 0.5 ||
            (mouthStats.stdDev < 10 &&
                Math.abs(mouthStats.mean - faceStats.mean) > 35)

        if (lowerFaceCovered) {
            return {
                blocked: true,
                message: 'Remove any mask, hand, or object covering the face.',
            }
        }

        return { blocked: false, message: '' }
    },

    async init() {
        this.video = this.$refs.video
        this.overlay = this.$refs.overlay
        this.captureCanvas = this.$refs.captureCanvas

        try {
            await this.loadModels()
            await this.loadExistingDescriptors(existingFaces)
            await this.startCamera()
            this.startScanLoop()
        } catch (error) {
            console.error(error)
            this.statusText =
                error instanceof Error
                    ? error.message
                    : 'Unable to start face registration.'
            this.message = this.statusText
            this.success = false
        }
    },

    destroy() {
        this.stopCamera()
        this.clearScanLoop()
    },

    async loadModels() {
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(this.modelPath),
            faceapi.nets.faceLandmark68Net.loadFromUri(this.modelPath),
            faceapi.nets.faceRecognitionNet.loadFromUri(this.modelPath),
        ])

        this.isModelReady = true
        this.statusText = 'Start the camera and center one face.'
    },

    async loadExistingDescriptors(faces) {
        this.existingDescriptors = []

        for (const face of faces) {
            if (!face.profile_url) continue

            try {
                const image = await faceapi.fetchImage(face.profile_url)
                const detection = await faceapi
                    .detectSingleFace(image, this.detectorOptions)
                    .withFaceLandmarks()
                    .withFaceDescriptor()

                if (!detection) continue

                this.existingDescriptors.push({
                    employeeId: face.employee_id,
                    name: face.name,
                    descriptor: detection.descriptor,
                })
            } catch (error) {
                console.error(
                    `Unable to read registered face for ${face.employee_id}`,
                    error,
                )
            }
        }
    },

    async startCamera() {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error(
                'Camera access is not available in this browser or context.',
            )
        }

        this.stream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: { ideal: 1280 },
                height: { ideal: 720 },
                facingMode: 'user',
            },
            audio: false,
        })

        this.video.srcObject = this.stream

        await new Promise((resolve) => {
            this.video.onloadedmetadata = () => {
                this.video.play()
                this.isCameraReady = true
                resolve()
            }
        })
    },

    stopCamera() {
        this.stream?.getTracks().forEach((track) => track.stop())
        this.stream = null
        this.isCameraReady = false
    },

    clearScanLoop() {
        if (this.scanInterval) {
            clearInterval(this.scanInterval)
            this.scanInterval = null
        }
    },

    startScanLoop() {
        this.clearScanLoop()

        this.scanInterval = setInterval(() => {
            this.scanFace().catch((error) => {
                console.error('Face enrollment scan failed.', error)
                this.statusText =
                    'Face scan failed. Check lighting and camera access.'
            })
        }, 700)
    },

    async scanFace() {
        if (
            !this.video ||
            !this.overlay ||
            !this.isCameraReady ||
            !this.isModelReady
        )
            return

        const displaySize = {
            width: this.video.clientWidth,
            height: this.video.clientHeight,
        }

        if (!displaySize.width || !displaySize.height) return

        faceapi.matchDimensions(this.overlay, displaySize)

        const detections = await faceapi
            .detectAllFaces(this.video, this.detectorOptions)
            .withFaceLandmarks()
            .withFaceDescriptors()

        this.faceCount = detections.length

        const context = this.overlay.getContext('2d')
        context?.clearRect(0, 0, this.overlay.width, this.overlay.height)

        const resizedDetections = faceapi.resizeResults(detections, displaySize)
        const oval = this.ovalBounds(displaySize.width, displaySize.height)
        this.faceInOval =
            detections.length === 1 &&
            this.isBoxInsideOval(resizedDetections[0].detection.box, oval) &&
            this.areLandmarksInsideOval(resizedDetections[0].landmarks, oval)
        this.faceClear = false

        if (!detections.length) {
            this.statusText = 'No face detected.'
            return
        }

        if (detections.length > 1) {
            this.statusText = 'Keep only one face in frame.'
            return
        }

        if (!this.faceInOval) {
            this.statusText = 'Center your face inside the oval.'
            return
        }

        const obstruction = this.analyzeFaceObstruction(
            resizedDetections[0],
            displaySize,
        )

        if (obstruction.blocked) {
            this.statusText = obstruction.message
            return
        }

        this.faceClear = true
        this.statusText = 'Face clear. Ready to save.'
    },

    captureBlob() {
        if (!this.video || !this.captureCanvas) return null

        const displaySize = {
            width: this.video.clientWidth,
            height: this.video.clientHeight,
        }

        const oval = this.ovalBounds(
            displaySize.width || this.video.videoWidth,
            displaySize.height || this.video.videoHeight,
        )
        const scaleX =
            this.video.videoWidth / (displaySize.width || this.video.videoWidth)
        const scaleY =
            this.video.videoHeight /
            (displaySize.height || this.video.videoHeight)
        const sourceX = Math.max(0, oval.x * scaleX)
        const sourceY = Math.max(0, oval.y * scaleY)
        const sourceWidth = Math.min(
            this.video.videoWidth - sourceX,
            oval.width * scaleX,
        )
        const sourceHeight = Math.min(
            this.video.videoHeight - sourceY,
            oval.height * scaleY,
        )

        this.captureCanvas.width = sourceWidth
        this.captureCanvas.height = sourceHeight

        const context = this.captureCanvas.getContext('2d')
        if (!context) return null

        context.drawImage(
            this.video,
            sourceX,
            sourceY,
            sourceWidth,
            sourceHeight,
            0,
            0,
            this.captureCanvas.width,
            this.captureCanvas.height,
        )
        this.capturedPreview = this.captureCanvas.toDataURL('image/jpeg', 0.9)

        const byteString = atob(this.capturedPreview.split(',')[1])
        const buffer = new Uint8Array(byteString.length)

        for (let i = 0; i < byteString.length; i++) {
            buffer[i] = byteString.charCodeAt(i)
        }

        return new Blob([buffer], { type: 'image/jpeg' })
    },

    async findDuplicateFace() {
        if (!this.video || !this.existingDescriptors.length) return null

        const detection = await faceapi
            .detectSingleFace(this.video, this.detectorOptions)
            .withFaceLandmarks()
            .withFaceDescriptor()

        if (!detection) return null

        let bestMatch = null

        for (const existing of this.existingDescriptors) {
            const distance = faceapi.euclideanDistance(
                existing.descriptor,
                detection.descriptor,
            )

            if (distance > this.duplicateThreshold) continue
            if (bestMatch && distance >= bestMatch.distance) continue

            bestMatch = {
                ...existing,
                distance,
            }
        }

        return bestMatch
    },

    async save() {
        if (this.faceCount !== 1 || !this.faceInOval || !this.faceClear) {
            this.message =
                'Center one clear, uncovered face inside the oval before saving.'
            this.success = false
            return
        }

        const duplicate = await this.findDuplicateFace()
        if (duplicate) {
            this.message = `This face is already registered to ${duplicate.name} (${duplicate.employeeId}).`
            this.success = false
            this.statusText = 'Duplicate registered face detected.'
            return
        }

        const image = this.captureBlob()
        if (!image) {
            this.message = 'Camera image is not ready.'
            this.success = false
            return
        }

        const formData = new FormData()
        formData.append(
            'face-image',
            image,
            `face_registration_${Date.now()}.jpg`,
        )

        this.isSubmitting = true
        this.message = ''
        this.statusText = 'Saving face registration...'

        try {
            const response = await fetch(registerUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            })

            const payload = await response.json().catch(() => ({}))

            if (!response.ok) {
                throw new Error(
                    payload.message ||
                        Object.values(payload.errors ?? {})?.[0]?.[0] ||
                        'Unable to save face registration.',
                )
            }

            this.success = true
            this.message = payload.message || 'Face registered successfully.'
            this.statusText = 'Face saved.'
        } catch (error) {
            this.success = false
            this.message =
                error instanceof Error
                    ? error.message
                    : 'Face registration failed.'
            this.statusText = 'Unable to save face registration.'
        } finally {
            this.isSubmitting = false
        }
    },
})
