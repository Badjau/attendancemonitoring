
const defaultFaceServiceUrl = 'https://127.0.0.1:8001'
const loopbackHosts = new Set(['127.0.0.1', 'localhost', '::1'])

const isLoopbackHost = (host) => loopbackHosts.has(String(host || '').toLowerCase())

export const faceServiceUrl = () => {
    const configuredUrl =
        import.meta.env.VITE_FACE_SERVICE_URL || defaultFaceServiceUrl

    return configuredUrl.replace(/\/$/, '')
}

export const faceServiceHealth = async (timeoutMs = 2500) => {
    const controller = new AbortController()
    const timeout = setTimeout(() => controller.abort(), timeoutMs)

    try {
        const response = await fetch(`${faceServiceUrl()}/health`, {
            signal: controller.signal,
            headers: {
                Accept: 'application/json',
            },
        })

        return jsonPayload(response, 'Face service unavailable.')
    } catch (error) {
        throw new Error('Face service unavailable.')
    } finally {
        clearTimeout(timeout)
    }
}

const jsonPayload = async (response, fallbackMessage) => {
    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
        const detail = Array.isArray(payload.detail)
            ? payload.detail
                  .map((error) => error.msg || error.message || String(error))
                  .join(' ')
            : payload.detail

        throw new Error(detail || payload.message || fallbackMessage)
    }

    return payload
}

export const recognizeFace = async (imageBlob) => {
    const formData = new FormData()
    formData.append('image', imageBlob, `face_deepface_${Date.now()}.jpg`)

    const response = await fetch(`${faceServiceUrl()}/api/recognize`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
        },
        body: formData,
    })

    return jsonPayload(response, 'Face recognition failed.')
}

export const verifyEmployeeFace = async (employeeId, imageBlob) => {
    const formData = new FormData()
    formData.append('image', imageBlob, `face_verify_${employeeId}_${Date.now()}.jpg`)

    const response = await fetch(
        `${faceServiceUrl()}/api/employees/${encodeURIComponent(employeeId)}/verify`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
            },
            body: formData,
        },
    )

    return jsonPayload(response, 'Face verification failed.')
}

export const detectFaces = async (imageBlob) => {
    const formData = new FormData()
    formData.append('image', imageBlob, `face_detect_${Date.now()}.jpg`)

    const response = await fetch(`${faceServiceUrl()}/api/detect`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
        },
        body: formData,
    })

    return jsonPayload(response, 'Face detection failed.')
}

export const enrollFace = async (employeeId, imageBlob, poseLabel = '', resetExisting = false) => {
    const formData = new FormData()
    formData.append('image', imageBlob, `face_enrollment_${employeeId}_${Date.now()}.jpg`)
    if (poseLabel) formData.append('pose_label', poseLabel)
    if (resetExisting) formData.append('reset_existing', 'true')
    const resetQuery = resetExisting ? '?reset_existing=true' : ''

    const response = await fetch(
        `${faceServiceUrl()}/api/employees/${encodeURIComponent(employeeId)}/enroll${resetQuery}`,
        {
            method: 'POST',
            headers: {
                Accept: 'application/json',
            },
            body: formData,
        },
    )

    return jsonPayload(response, 'Face enrollment failed.')
}

export const faceEnrollmentStatus = async (employeeId) => {
    const response = await fetch(
        `${faceServiceUrl()}/api/employees/${encodeURIComponent(employeeId)}/status`,
        {
            headers: {
                Accept: 'application/json',
            },
        },
    )

    return jsonPayload(response, 'Unable to read face enrollment status.')
}
