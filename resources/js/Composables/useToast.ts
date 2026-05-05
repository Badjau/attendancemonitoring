import { ref } from 'vue'

export type ToastType = 'in' | 'out' | 'success' | 'error'

export type Toast = {
    id: number
    type: ToastType
    message: string
    time?: string
}

const toasts = ref<Toast[]>([])

export function useToast() {
    const showToast = (toast: Omit<Toast, 'id'>) => {
        const id = Date.now()

        toasts.value.push({
            id,
            ...toast,
        })

        setTimeout(() => {
            toasts.value = toasts.value.filter(t => t.id !== id)
        }, 3000)
    }

    return {
        toasts,
        showToast,
    }
}
