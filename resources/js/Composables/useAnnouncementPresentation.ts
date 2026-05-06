type AnnouncementType = "general" | "urgent" | "event" | "holiday" | "policy";

const typeStyles: Record<AnnouncementType, { label: string; accent: string; soft: string; text: string }> = {
    general: {
        label: "General",
        accent: "#004643",
        soft: "rgba(0, 70, 67, 0.14)",
        text: "#001e1d",
    },
    urgent: {
        label: "Urgent",
        accent: "#e16162",
        soft: "rgba(225, 97, 98, 0.16)",
        text: "#001e1d",
    },
    event: {
        label: "Event",
        accent: "#abd1c6",
        soft: "rgba(171, 209, 198, 0.35)",
        text: "#001e1d",
    },
    holiday: {
        label: "Holiday",
        accent: "#f9bc60",
        soft: "rgba(249, 188, 96, 0.24)",
        text: "#001e1d",
    },
    policy: {
        label: "Policy",
        accent: "#001e1d",
        soft: "rgba(0, 30, 29, 0.12)",
        text: "#001e1d",
    },
};

export function useAnnouncementPresentation() {
    const getAnnouncementType = (announcement: any): AnnouncementType => {
        const type = typeof announcement?.type === "string"
            ? announcement.type
            : announcement?.type?.value;

        return Object.hasOwn(typeStyles, type) ? type as AnnouncementType : "general";
    };

    const getAnnouncementStyle = (announcement: any) => typeStyles[getAnnouncementType(announcement)];

    const getAnnouncementAttachments = (announcement: any) => announcement?.media ?? [];

    const isImageAttachment = (attachment: any) => String(attachment?.mime_type ?? "").startsWith("image/");

    const getAttachmentUrl = (attachment: any) => attachment?.original_url ?? attachment?.url ?? "#";

    const getAttachmentName = (attachment: any) => attachment?.name ?? attachment?.file_name ?? "Attachment";

    return {
        getAnnouncementAttachments,
        getAnnouncementStyle,
        getAnnouncementType,
        getAttachmentName,
        getAttachmentUrl,
        isImageAttachment,
    };
}
