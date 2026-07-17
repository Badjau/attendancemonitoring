<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'

type AttendanceAction = 'time-in' | 'time-out'
type TapEvent = 'time-in' | 'break-start' | 'break-end' | 'time-out'
type AttendanceGreeting = {
    first_name: string
    is_birthday: boolean
    attendance_type: AttendanceAction
    tap_event?: TapEvent
}

const icon = ref('👋')
const title = ref(null)
const subtitle = ref('Ready to clock in?')
const speechVoices = ref<SpeechSynthesisVoice[]>([])

const getDayGreeting = () => {
    const hour = new Date().getHours()

    if (hour < 12) return 'Good morning'
    if (hour < 18) return 'Good afternoon'

    return 'Good evening'
}

const preferredVoiceNames = [
    'Microsoft Jenny',
    'Microsoft Aria',
    'Microsoft Zira',
    'Google US English',
    'Samantha',
    'Karen',
]

const getEnglishVoice = () => {
    const voices = speechVoices.value.length
        ? speechVoices.value
        : window.speechSynthesis.getVoices()
    const englishVoices = voices.filter((voice) => voice.lang.startsWith('en'))

    return (
        preferredVoiceNames
            .map((name) =>
                englishVoices.find((voice) => voice.name.includes(name)),
            )
            .find((voice): voice is SpeechSynthesisVoice => Boolean(voice)) ??
        englishVoices.find((voice) => voice.lang === 'en-US') ??
        englishVoices[0] ??
        null
    )
}

const loadSpeechVoices = () => {
    if (!('speechSynthesis' in window)) return

    speechVoices.value = window.speechSynthesis.getVoices()
}

const speakGreeting = (message: string) => {
    if (!('speechSynthesis' in window)) return

    window.speechSynthesis.cancel()

    const utterance = new SpeechSynthesisUtterance(message)
    utterance.lang = 'en-US'
    utterance.rate = 0.95
    utterance.pitch = 1

    utterance.voice = getEnglishVoice()

    window.speechSynthesis.speak(utterance)
}

const buildGreetingMessage = (greeting: AttendanceGreeting) => {
    const dayGreeting = getDayGreeting()
    const tapEvent = greeting.tap_event ?? greeting.attendance_type
    const attendanceMessage =
        tapEvent === 'time-out'
            ? `${dayGreeting}, ${greeting.first_name}. Time out recorded. Take care.`
            : tapEvent === 'break-start'
              ? `${dayGreeting}, ${greeting.first_name}. Break started.`
              : tapEvent === 'break-end'
                ? `${dayGreeting}, ${greeting.first_name}. Break ended.`
                : `${dayGreeting}, ${greeting.first_name}. Time in recorded. Have a productive day.`

    if (!greeting.is_birthday || tapEvent !== 'time-in')
        return attendanceMessage

    return `${dayGreeting}, ${greeting.first_name}. Happy birthday! Time in recorded. Have a productive day.`
}

const onAttendanceGreeting = (event: Event) => {
    const greeting = (event as CustomEvent<AttendanceGreeting>).detail
    if (!greeting?.first_name) return

    const dayGreeting = getDayGreeting()
    const tapEvent = greeting.tap_event ?? greeting.attendance_type

    icon.value = greeting.is_birthday ? '🎂' : '👋'
    title.value = `${dayGreeting}, ${greeting.first_name}`
    subtitle.value = greeting.is_birthday && tapEvent === 'time-in'
        ? 'Happy birthday! Attendance recorded.'
        : tapEvent === 'time-out'
          ? 'Time out recorded. Take care.'
          : tapEvent === 'break-start'
            ? 'Break started.'
            : tapEvent === 'break-end'
              ? 'Break ended.'
              : 'Time in recorded. Have a productive day.'

    speakGreeting(buildGreetingMessage(greeting))
}

onMounted(() => {
    window.addEventListener('attendance:greeting', onAttendanceGreeting)
    loadSpeechVoices()
    window.speechSynthesis?.addEventListener('voiceschanged', loadSpeechVoices)
})

onUnmounted(() => {
    window.removeEventListener('attendance:greeting', onAttendanceGreeting)
    window.speechSynthesis?.removeEventListener(
        'voiceschanged',
        loadSpeechVoices,
    )
    window.speechSynthesis?.cancel()
})
</script>

<template>
    <div
        id="greeting-card"
        class="rounded-2xl border border-black/5 bg-white p-4 shadow-xl shadow-black/5 animate-fade-up"
    >
        <div class="flex items-center gap-4">
            <div
                id="greeting-icon"
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-bg/10 text-xl"
            >
                {{ icon }}
            </div>

            <div class="min-w-0">
                <p
                    id="greeting-text"
                    class="wrap-break-word text-lg font-black leading-tight text-brand-stroke"
                >
                    {{ title ?? getDayGreeting() }}
                </p>
                <p
                    id="greeting-subtext"
                    class="wrap-break-word text-sm font-semibold leading-snug text-black/55"
                >
                    {{ subtitle }}
                </p>
            </div>
        </div>
    </div>
</template>
