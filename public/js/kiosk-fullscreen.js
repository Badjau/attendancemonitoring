(function () {
    const fullscreenEnabled =
        document.fullscreenEnabled ||
        document.webkitFullscreenEnabled ||
        document.msFullscreenEnabled

    const standaloneQuery = window.matchMedia('(display-mode: fullscreen), (display-mode: standalone)')

    const isStandalone = () =>
        standaloneQuery.matches ||
        window.navigator.standalone === true

    const fullscreenElement = () =>
        document.fullscreenElement ||
        document.webkitFullscreenElement ||
        document.msFullscreenElement

    const requestFullscreen = () => {
        if (!fullscreenEnabled || fullscreenElement() || isStandalone()) {
            return
        }

        const root = document.documentElement
        const request =
            root.requestFullscreen ||
            root.webkitRequestFullscreen ||
            root.msRequestFullscreen

        if (!request) return

        Promise.resolve(request.call(root)).catch(() => {})
    }

    const armFullscreenRequest = () => {
        if (!fullscreenEnabled || fullscreenElement() || isStandalone()) {
            return
        }

        const options = { capture: true, once: true, passive: true }

        window.addEventListener('pointerdown', requestFullscreen, options)
        window.addEventListener('keydown', requestFullscreen, options)
        window.addEventListener('touchstart', requestFullscreen, options)
    }

    document.addEventListener('DOMContentLoaded', armFullscreenRequest)
    document.addEventListener('fullscreenchange', armFullscreenRequest)
    document.addEventListener('webkitfullscreenchange', armFullscreenRequest)
    document.addEventListener('msfullscreenchange', armFullscreenRequest)
})()
