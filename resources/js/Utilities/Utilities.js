export const nlDate = (date) => {
    return new Date(date).toLocaleDateString("nl-NL", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });
};

export const nlTime = (date) => {
    return new Date(date).toLocaleTimeString("nl-NL", {
        hour: "2-digit",
        minute: "2-digit",
    });
};

export const mapsLinkFromCustomer = (customer) => {
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(
        customer.address + " " + customer.postal_code + " " + customer.city
    )}`;
};
