const defaultFaceServiceUrl = 'https://127.0.0.1:8001'

export const faceServiceUrl = () =>
    (import.meta.env.VITE_FACE_SERVICE_URL || defaultFaceServiceUrl).replace(
        /\/$/,
        '',
    )

const jsonPayload = async (response, fallbackMessage) => {
    const payload = await response.json().catch(() => ({}))

    if (!response.ok) {
        throw new Error(payload.detail || payload.message || fallbackMessage)
    }

    return payload
}

export const recognizeFace = async (imageBlob) => {
    const formData = new FormData()
    formData.append('image', imageBlob, `face_recognition_${Date.now()}.jpg`)

    const response = await fetch(`${faceServiceUrl()}/api/recognize`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
        },
        body: formData,
    })

    return jsonPayload(response, 'Face recognition failed.')
}

export const enrollFace = async (employeeId, imageBlob, poseLabel = '') => {
    const formData = new FormData()
    formData.append('image', imageBlob, `face_enrollment_${employeeId}_${Date.now()}.jpg`)
    if (poseLabel) formData.append('pose_label', poseLabel)

    const response = await fetch(
        `${faceServiceUrl()}/api/employees/${encodeURIComponent(employeeId)}/enroll`,
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
