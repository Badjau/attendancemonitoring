import axios from 'axios'
window.axios = axios

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

const csrfToken = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content')

if (csrfToken) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken
}

window.axios.interceptors.request.use((config) => {
    const freshToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content')

    if (freshToken) {
        config.headers = config.headers || {}
        config.headers['X-CSRF-TOKEN'] = freshToken
    }

    return config
})
