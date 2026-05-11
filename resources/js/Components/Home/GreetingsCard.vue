<script setup lang="ts">
import {onMounted, onUnmounted, ref} from "vue";

type AttendanceAction = "time-in" | "time-out"
type AttendanceGreeting = {
    first_name: string
    is_birthday: boolean
    attendance_type: AttendanceAction
}

const icon = ref("👋")
const title = ref(null)
const subtitle = ref("Ready to clock in?")
const speechVoices = ref<SpeechSynthesisVoice[]>([])

const getDayGreeting = () => {
    const hour = new Date().getHours()

    if (hour < 12) return "Good morning"
    if (hour < 18) return "Good afternoon"

    return "Good evening"
}

const preferredVoiceNames = [
    "Microsoft Jenny",
    "Microsoft Aria",
    "Microsoft Zira",
    "Google US English",
    "Samantha",
    "Karen",
]

const getEnglishVoice = () => {
    const voices = speechVoices.value.length
        ? speechVoices.value
        : window.speechSynthesis.getVoices()
    const englishVoices = voices.filter((voice) => voice.lang.startsWith("en"))

    return preferredVoiceNames
        .map((name) => englishVoices.find((voice) => voice.name.includes(name)))
        .find((voice): voice is SpeechSynthesisVoice => Boolean(voice))
        ?? englishVoices.find((voice) => voice.lang === "en-US")
        ?? englishVoices[0]
        ?? null
}

const loadSpeechVoices = () => {
    if (!("speechSynthesis" in window)) return

    speechVoices.value = window.speechSynthesis.getVoices()
}

const speakGreeting = (message: string) => {
    if (!("speechSynthesis" in window)) return

    window.speechSynthesis.cancel()

    const utterance = new SpeechSynthesisUtterance(message)
    utterance.lang = "en-US"
    utterance.rate = 0.95
    utterance.pitch = 1

    utterance.voice = getEnglishVoice()

    window.speechSynthesis.speak(utterance)
}

const buildGreetingMessage = (greeting: AttendanceGreeting) => {
    const dayGreeting = getDayGreeting()
    const attendanceMessage = greeting.attendance_type === "time-out"
        ? `${dayGreeting}, ${greeting.first_name}. Time out recorded. Take care.`
        : `${dayGreeting}, ${greeting.first_name}. Time in recorded. Have a productive day.`

    if (!greeting.is_birthday || greeting.attendance_type === "time-out") return attendanceMessage

    return `${dayGreeting}, ${greeting.first_name}. Happy birthday! Time in recorded. Have a productive day.`
}

const onAttendanceGreeting = (event: Event) => {
    const greeting = (event as CustomEvent<AttendanceGreeting>).detail
    if (!greeting?.first_name) return

    const dayGreeting = getDayGreeting()

    icon.value = greeting.is_birthday ? "🎂" : "👋"
    title.value = `${dayGreeting}, ${greeting.first_name}`
    subtitle.value = greeting.is_birthday
        ? "Happy birthday! Attendance recorded."
        : greeting.attendance_type === "time-out"
            ? "Time out recorded. Take care."
            : "Time in recorded. Have a productive day."

    speakGreeting(buildGreetingMessage(greeting))
}

onMounted(() => {
    window.addEventListener('attendance:greeting', onAttendanceGreeting)
    loadSpeechVoices()
    window.speechSynthesis?.addEventListener("voiceschanged", loadSpeechVoices)
})

onUnmounted(() => {
    window.removeEventListener('attendance:greeting', onAttendanceGreeting)
    window.speechSynthesis?.removeEventListener("voiceschanged", loadSpeechVoices)
    window.speechSynthesis?.cancel()
})
</script>

<template>
    <div
        id="greeting-card"
        class="bg-brand-card rounded-4xl p-6 border-2 border-brand-stroke shadow-[6px_6px_0px_0px_#001e1d] animate-fade-up"
    >
        <div class="flex items-center gap-4">
            <div
                id="greeting-icon"
                class="w-12 h-12 shrink-0 flex items-center justify-center rounded-xl border border-brand-stroke bg-white text-xl"
            >
                {{ icon }}
            </div>

            <div class="min-w-0">
                <p id="greeting-text" class="text-lg font-bold leading-tight text-brand-stroke wrap-break-word">
                    {{ title ?? getDayGreeting() }}
                </p>
                <p
                    id="greeting-subtext"
                    class="text-xs font-semibold leading-snug text-brand-bg opacity-70 wrap-break-word"
                >
                    {{ subtitle }}
                </p>
            </div>
        </div>
    </div>
</template>
