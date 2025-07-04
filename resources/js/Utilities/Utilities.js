export const nlDate = (date) => {
    return new Date(date).toLocaleDateString("nl-NL", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });
};
