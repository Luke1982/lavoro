export const nlDate = (date) => {
    return new Date(date).toLocaleDateString("nl-NL", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });
};

export const formatLocalDateAsISO = (date) => {
    const pad = (n) => String(n).padStart(2, "0");
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(
        date.getDate()
    )}`;
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

import { usePage } from "@inertiajs/vue3";

export const hasPermission = (permission) => {
    const page = usePage();
    const auth = page?.props?.auth || {};
    if (auth.isAdmin) {
        return true;
    }
    const perms = new Set(auth.permissions || []);
    return perms.has(permission);
};

export const hasAnyPermission = (permissions) => {
    const page = usePage();
    const auth = page?.props?.auth || {};
    if (auth.isAdmin) {
        return true;
    }
    const perms = new Set(auth.permissions || []);
    if (Array.isArray(permissions)) {
        return permissions.some((p) => perms.has(p));
    }
    return perms.has(permissions);
};

export const serviceOrderSentState = (order) => {
    if (!order) return "none";
    const a = !!order.sent_to_administration;
    const c = !!order.sent_to_customer;
    if (a && c) return "both";
    if (a && !c) return "administration";
    if (!a && c) return "customer";
    return "none";
};

export const serviceOrderPillText = (order) => {
    switch (serviceOrderSentState(order)) {
        case "both":
            return "Verzonden klant & administratie";
        case "administration":
            return "Verzonden administratie";
        case "customer":
            return "Verzonden klant";
        default:
            return "Niet verzonden";
    }
};

export const serviceOrderPillColorClasses = (order) => {
    switch (serviceOrderSentState(order)) {
        case "both":
        case "administration":
            return "bg-green-100 text-green-700 border-green-300 dark:bg-green-900/30 dark:text-green-300 dark:border-green-700/50";
        case "customer":
            return "bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-700/50";
        default:
            return "bg-gray-100 text-gray-600 border-gray-300 dark:bg-slate-700/40 dark:text-slate-300 dark:border-slate-600";
    }
};
