export const objectCoverMetrics = (video) => {
    const sourceWidth = video.videoWidth || video.clientWidth
    const sourceHeight = video.videoHeight || video.clientHeight
    const targetWidth = video.clientWidth
    const targetHeight = video.clientHeight
    const scale = Math.max(
        targetWidth / sourceWidth,
        targetHeight / sourceHeight,
    )
    const renderedWidth = sourceWidth * scale
    const renderedHeight = sourceHeight * scale

    return {
        scale,
        offsetX: (targetWidth - renderedWidth) / 2,
        offsetY: (targetHeight - renderedHeight) / 2,
    }
}

export const mapFaceBoxToObjectCover = (box, video) => {
    if (
        !box ||
        !Number.isFinite(box.x) ||
        !Number.isFinite(box.y) ||
        !Number.isFinite(box.width) ||
        !Number.isFinite(box.height) ||
        box.width <= 0 ||
        box.height <= 0
    ) {
        return null
    }

    const metrics = objectCoverMetrics(video)

    return {
        x: box.x * metrics.scale + metrics.offsetX,
        y: box.y * metrics.scale + metrics.offsetY,
        width: box.width * metrics.scale,
        height: box.height * metrics.scale,
    }
}

export const mapFacePointToObjectCover = (point, video) => {
    const metrics = objectCoverMetrics(video)

    return {
        x: point.x * metrics.scale + metrics.offsetX,
        y: point.y * metrics.scale + metrics.offsetY,
    }
}

export const mapFaceLandmarksToObjectCover = (landmarks, video) => ({
    getJawOutline: () =>
        landmarks
            .getJawOutline()
            .map((point) => mapFacePointToObjectCover(point, video)),
    getLeftEye: () =>
        landmarks
            .getLeftEye()
            .map((point) => mapFacePointToObjectCover(point, video)),
    getRightEye: () =>
        landmarks
            .getRightEye()
            .map((point) => mapFacePointToObjectCover(point, video)),
    getNose: () =>
        landmarks
            .getNose()
            .map((point) => mapFacePointToObjectCover(point, video)),
    getMouth: () =>
        landmarks
            .getMouth()
            .map((point) => mapFacePointToObjectCover(point, video)),
})
