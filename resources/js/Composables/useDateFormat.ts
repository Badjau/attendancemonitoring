export function useDateFormat() {
    const formatDate = (date: any) => {
        if (!date) return '-'

        return new Date(date).toLocaleDateString('en-PH', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
        })
    }

    const formatDateTime = (date: any) => {
        if (!date) return '-'
        return new Date(date).toLocaleString('en-PH', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        })
    }

    const formatTime = (date: any) => {
        if (!date) return '-'
        return new Date(date).toLocaleTimeString('en-PH', {
            hour: '2-digit',
            minute: '2-digit',
        })
    }

    return { formatDate, formatDateTime, formatTime }
}
